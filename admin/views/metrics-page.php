<?php
defined('ABSPATH') || exit;

$from = isset($_GET['aib_from']) ? sanitize_text_field((string)$_GET['aib_from']) : '';
$to = isset($_GET['aib_to']) ? sanitize_text_field((string)$_GET['aib_to']) : '';
$type_filter = isset($_GET['aib_type']) ? sanitize_key((string)$_GET['aib_type']) : 'all';
$model_filter = isset($_GET['aib_model']) ? sanitize_text_field((string)$_GET['aib_model']) : '';
$export = isset($_GET['aib_export']) ? sanitize_key((string)$_GET['aib_export']) : '';
$selected_post_id = isset($_GET['aib_post_detail']) ? max(0, (int)$_GET['aib_post_detail']) : 0;

$allowed_types = ['all', 'post', 'text', 'image', 'extra'];
if (!in_array($type_filter, $allowed_types, true)) {
    $type_filter = 'all';
}

$from_ok = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $from);
$to_ok = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $to);

$from_ts = $from_ok ? strtotime($from . ' 00:00:00') : null;
$to_ts = $to_ok ? strtotime($to . ' 23:59:59') : null;

$posts_page = isset($_GET['aib_posts_page']) ? max(1, (int)$_GET['aib_posts_page']) : 1;
$posts_per_page = isset($_GET['aib_posts_per_page']) ? (int)$_GET['aib_posts_per_page'] : 25;
if (!in_array($posts_per_page, [25, 50], true)) {
    $posts_per_page = 25;
}

$types_query = ($type_filter !== 'all') ? [$type_filter] : [];

$usage_log_all = AIBlogger_Utils::get_usage_log_range($from_ts, $to_ts, 20000, $types_query);
if ($model_filter !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($model_filter, 'UTF-8') : strtolower($model_filter);
    $usage_log_all = array_values(array_filter($usage_log_all, function ($e) use ($needle) {
        if (!is_array($e)) return false;
        $model = (string)($e['model'] ?? '');
        $hay = function_exists('mb_strtolower') ? mb_strtolower($model, 'UTF-8') : strtolower($model);
        return strpos($hay, $needle) !== false;
    }));
}

$usage_log_recent = array_slice($usage_log_all, 0, 20);
$usage_log_system = array_values(array_filter($usage_log_all, function ($e) {
    return is_array($e) && (string)($e['type'] ?? '') !== 'post';
}));
$usage_tot_system = AIBlogger_Utils::aggregate_usage_log($usage_log_system);
$budget = AIBlogger_Utils::site_token_budget_status();

$usage_by_day = [];
foreach ($usage_log_system as $e) {
    if (!is_array($e)) continue;
    $ts = (int)($e['ts'] ?? 0);
    if ($ts <= 0) continue;

    $day = date_i18n('Y-m-d', $ts);
    if (!isset($usage_by_day[$day])) {
        $usage_by_day[$day] = [
            'calls' => 0,
            'usage' => AIBlogger_Utils::aggregate_usage([]),
        ];
    }

    $usage_by_day[$day]['calls']++;
    $sum = AIBlogger_Utils::aggregate_usage([[
        'type' => (string)($e['type'] ?? 'extra'),
        'usage' => is_array($e['usage'] ?? null) ? $e['usage'] : [],
    ]]);

    foreach ($usage_by_day[$day]['usage'] as $k => $v) {
        $usage_by_day[$day]['usage'][$k] += (int)($sum[$k] ?? 0);
    }
}
krsort($usage_by_day);

$date_query = [];
if ($from_ok || $to_ok) {
    $dq = [];
    if ($from_ok) $dq['after'] = $from;
    if ($to_ok) $dq['before'] = $to;
    $dq['inclusive'] = true;
    $date_query[] = $dq;
}

$post_meta_query = [];
if ($model_filter !== '') {
    $post_meta_query[] = [
        'key' => '_aiblogger_metrics',
        'value' => $model_filter,
        'compare' => 'LIKE',
    ];
}

$q_args = [
    'post_type' => 'any',
    'posts_per_page' => $posts_per_page,
    'paged' => $posts_page,
    'meta_key' => '_aiblogger_metrics',
    'orderby' => 'date',
    'order' => 'DESC',
];
if (!empty($date_query)) {
    $q_args['date_query'] = $date_query;
}
if (!empty($post_meta_query)) {
    $q_args['meta_query'] = $post_meta_query;
}

$q = new WP_Query($q_args);

$base_params = [
    'page' => 'aiblogger_metrics',
    'aib_posts_per_page' => $posts_per_page,
    'aib_type' => $type_filter,
    'aib_model' => $model_filter,
];
if ($from_ok) $base_params['aib_from'] = $from;
if ($to_ok) $base_params['aib_to'] = $to;

if (in_array($export, ['actions', 'posts'], true)) {
    if (!AIBlogger_Utils::can_generate()) {
        wp_die('Sin permisos.');
    }

    nocache_headers();
    $filename = 'aiblogger-' . $export . '-' . gmdate('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $out = fopen('php://output', 'w');
    if (!$out) exit;

    if ($export === 'actions') {
        fputcsv($out, ['accion', 'tipo', 'fecha', 'modelo', 'total', 'prompt', 'salida', 'tool', 'thought', 'cached']);
        foreach ($usage_log_all as $e) {
            if (!is_array($e)) continue;
            $u = is_array($e['usage'] ?? null) ? $e['usage'] : [];
            $ts = (int)($e['ts'] ?? 0);
            fputcsv($out, [
                (string)($e['label'] ?? ''),
                (string)($e['type'] ?? ''),
                $ts > 0 ? date_i18n('Y-m-d H:i:s', $ts) : '',
                (string)($e['model'] ?? ''),
                (int)($u['total_tokens'] ?? 0),
                (int)($u['prompt_tokens'] ?? 0),
                (int)($u['output_tokens'] ?? 0),
                (int)($u['tool_tokens'] ?? 0),
                (int)($u['thought_tokens'] ?? 0),
                (int)($u['cached_tokens'] ?? 0),
            ]);
        }
    } else {
        $q_export_args = [
            'post_type' => 'any',
            'posts_per_page' => 5000,
            'meta_key' => '_aiblogger_metrics',
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        if (!empty($date_query)) {
            $q_export_args['date_query'] = $date_query;
        }
        if (!empty($post_meta_query)) {
            $q_export_args['meta_query'] = $post_meta_query;
        }

        $q_export = new WP_Query($q_export_args);
        fputcsv($out, ['post_id', 'titulo', 'fecha', 'total', 'texto', 'imagenes', 'imagenes_count', 'extra', 'prompt', 'salida', 'tool', 'thought', 'cached', 'modelo_texto', 'modelo_imagen', 'estimado_usd', 'worker_reintentos', 'worker_tiempo_s', 'url_editar']);

        if ($q_export->have_posts()) {
            while ($q_export->have_posts()) {
                $q_export->the_post();
                $pid = get_the_ID();
                $m = get_post_meta($pid, '_aiblogger_metrics', true);
                $m = is_array($m) ? $m : [];
                $ws = get_post_meta($pid, '_aiblogger_worker_stats', true);
                $ws = is_array($ws) ? $ws : [];
                $usage_for_cost = [
                    'prompt_tokens' => (int)($m['prompt_tokens'] ?? 0),
                    'output_tokens' => (int)($m['output_tokens'] ?? 0),
                    'tool_tokens' => (int)($m['tool_tokens'] ?? 0),
                    'thought_tokens' => (int)($m['thought_tokens'] ?? 0),
                    'cached_tokens' => (int)($m['cached_tokens'] ?? 0),
                ];
                $model_for_cost = (string)($m['text_model'] ?? $m['image_model'] ?? '');
                $estimated_usd = AIBlogger_Utils::estimate_usage_cost_usd($usage_for_cost, $model_for_cost);

                fputcsv($out, [
                    $pid,
                    get_the_title(),
                    get_the_date('Y-m-d H:i:s'),
                    (int)($m['total_tokens'] ?? 0),
                    (int)($m['text_total_tokens'] ?? 0),
                    (int)($m['image_total_tokens'] ?? 0),
                    (int)($m['image_calls'] ?? 0),
                    (int)($m['extra_total_tokens'] ?? 0),
                    (int)($m['prompt_tokens'] ?? 0),
                    (int)($m['output_tokens'] ?? 0),
                    (int)($m['tool_tokens'] ?? 0),
                    (int)($m['thought_tokens'] ?? 0),
                    (int)($m['cached_tokens'] ?? 0),
                    (string)($m['text_model'] ?? ''),
                    (string)($m['image_model'] ?? ''),
                    (float)$estimated_usd,
                    (int)($ws['attempts'] ?? 0),
                    (float)($ws['total_seconds'] ?? 0),
                    get_edit_post_link($pid, ''),
                ]);
            }
            wp_reset_postdata();
        }
    }

    fclose($out);
    exit;
}

$post_detail_metrics = [];
if ($selected_post_id > 0) {
    $tmp = get_post_meta($selected_post_id, '_aiblogger_metrics', true);
    if (is_array($tmp)) {
        $post_detail_metrics = $tmp;
    }
}
?>
<div class="wrap aiblogger-wrap">
  <section class="aib-page-head">
    <h1>AIBlogger - M&eacute;tricas</h1>
    <p>Consulta consumo de tokens, presupuesto global y detalle por acciones y posts.</p>
  </section>

  <div class="aib-grid">
    <section class="aib-card">
      <h2>Presupuesto global actual</h2>
      <div class="aib-kpi">
        <div class="aib-kpi-item">
          <strong>Diario</strong>
          <div><?php echo esc_html((string)((int)($budget['used_daily'] ?? 0))); ?> / <?php echo esc_html((string)((int)($budget['daily_limit'] ?? 0))); ?> <?php if (empty($budget['daily_limit'])) : ?>(sin l&iacute;mite)<?php endif; ?></div>
        </div>
        <div class="aib-kpi-item">
          <strong>Mensual</strong>
          <div><?php echo esc_html((string)((int)($budget['used_monthly'] ?? 0))); ?> / <?php echo esc_html((string)((int)($budget['monthly_limit'] ?? 0))); ?> <?php if (empty($budget['monthly_limit'])) : ?>(sin l&iacute;mite)<?php endif; ?></div>
        </div>
        <div class="aib-kpi-item">
          <strong>Modo</strong>
          <div><?php echo esc_html((string)($budget['mode'] ?? 'warn')); ?></div>
        </div>
      </div>

      <form method="get" class="aib-actions" style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <input type="hidden" name="page" value="aiblogger_metrics" />

        <label>Desde: <input type="date" name="aib_from" value="<?php echo esc_attr($from_ok ? $from : ''); ?>" /></label>
        <label>Hasta: <input type="date" name="aib_to" value="<?php echo esc_attr($to_ok ? $to : ''); ?>" /></label>

        <label>Tipo:
          <select name="aib_type">
            <option value="all" <?php selected($type_filter, 'all'); ?>>Todos</option>
            <option value="post" <?php selected($type_filter, 'post'); ?>>Post</option>
            <option value="text" <?php selected($type_filter, 'text'); ?>>Texto</option>
            <option value="image" <?php selected($type_filter, 'image'); ?>>Imagen</option>
            <option value="extra" <?php selected($type_filter, 'extra'); ?>>Extra</option>
          </select>
        </label>

        <label>Modelo: <input type="text" name="aib_model" value="<?php echo esc_attr($model_filter); ?>" placeholder="Ej: gemini-2.5" /></label>

        <label>Posts por p&aacute;gina:
          <select name="aib_posts_per_page">
            <option value="25" <?php selected($posts_per_page, 25); ?>>25</option>
            <option value="50" <?php selected($posts_per_page, 50); ?>>50</option>
          </select>
        </label>

        <button type="submit" class="button">Filtrar</button>
        <button type="submit" class="button" name="aib_export" value="actions">Exportar acciones CSV</button>
        <button type="submit" class="button" name="aib_export" value="posts">Exportar posts CSV</button>
        <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=aiblogger_metrics')); ?>">Limpiar</a>
      </form>
    </section>

    <section class="aib-card">
      <h2>&Uacute;ltimas 20 acciones</h2>
      <?php if (empty($usage_log_recent)) : ?>
        <p>No hay acciones registradas en el rango y filtros seleccionados.</p>
      <?php else : ?>
        <div class="aib-table-wrap">
          <table class="widefat striped">
            <thead><tr><th>Acci&oacute;n</th><th>Tipo</th><th>Fecha</th><th>Total</th><th>Prompt</th><th>Salida</th><th>Tool</th><th>Thought</th><th>Cached</th><th>Modelo</th></tr></thead>
            <tbody>
            <?php foreach ($usage_log_recent as $e) :
                $u = is_array($e['usage'] ?? null) ? ($e['usage'] ?? []) : [];
                $ts = (int)($e['ts'] ?? 0);
            ?>
              <tr>
                <td><?php echo esc_html((string)($e['label'] ?? '')); ?></td>
                <td><?php echo esc_html((string)($e['type'] ?? '')); ?></td>
                <td><?php echo esc_html($ts ? date_i18n('Y-m-d H:i', $ts) : ''); ?></td>
                <td><?php echo esc_html((string)((int)($u['total_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['prompt_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['output_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['tool_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['thought_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['cached_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)($e['model'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="aib-card">
      <h2>Estad&iacute;sticas de acciones del sistema</h2>
      <?php if (empty($usage_log_system)) : ?>
        <p>No hay acciones del sistema para el rango y filtros seleccionados.</p>
      <?php else : ?>
        <p class="description">Total del periodo: <strong><?php echo esc_html((string)((int)($usage_tot_system['total_tokens'] ?? 0))); ?></strong> tokens (sin incluir eventos tipo <code>post</code>).</p>

        <div class="aib-table-wrap">
          <table class="widefat striped">
            <thead><tr><th>Fecha</th><th>Llamadas</th><th>Total</th><th>Prompt</th><th>Salida</th><th>Tool</th><th>Thought</th><th>Cached</th></tr></thead>
            <tbody>
            <?php foreach ($usage_by_day as $day => $row) :
                $u = is_array($row['usage'] ?? null) ? $row['usage'] : [];
            ?>
              <tr>
                <td><?php echo esc_html($day); ?></td>
                <td><?php echo esc_html((string)((int)($row['calls'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['total_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['prompt_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['output_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['tool_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['thought_tokens'] ?? 0))); ?></td>
                <td><?php echo esc_html((string)((int)($u['cached_tokens'] ?? 0))); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="aib-card">
      <h2>M&eacute;tricas por post</h2>
      <?php if (!$q->have_posts()) : ?>
        <p>No hay posts con m&eacute;tricas en el rango y filtros seleccionados.</p>
      <?php else : ?>
        <div class="aib-table-wrap">
          <table class="widefat striped">
            <thead>
              <tr>
                <th>Post</th>
                <th>Fecha</th>
                <th>Total</th>
                <th>Texto</th>
                <th>Im&aacute;genes</th>
                <th>Cant. im&aacute;genes</th>
                <th>Extra</th>
                <th>Prompt</th>
                <th>Salida</th>
                <th>Tool</th>
                <th>Thought</th>
                <th>Cached</th>
                <th>Modelo texto</th>
                <th>Modelo imagen</th>
                <th>Est. USD</th>
                <th>Reintentos</th>
                <th>Tiempo (s)</th>
                <th>Detalle</th>
              </tr>
            </thead>
            <tbody>
            <?php
            while ($q->have_posts()) {
                $q->the_post();
                $pid = get_the_ID();
                $m = get_post_meta($pid, '_aiblogger_metrics', true);
                $m = is_array($m) ? $m : [];
                $ws = get_post_meta($pid, '_aiblogger_worker_stats', true);
                $ws = is_array($ws) ? $ws : [];
                $usage_for_cost = [
                    'prompt_tokens' => (int)($m['prompt_tokens'] ?? 0),
                    'output_tokens' => (int)($m['output_tokens'] ?? 0),
                    'tool_tokens' => (int)($m['tool_tokens'] ?? 0),
                    'thought_tokens' => (int)($m['thought_tokens'] ?? 0),
                    'cached_tokens' => (int)($m['cached_tokens'] ?? 0),
                ];
                $model_for_cost = (string)($m['text_model'] ?? $m['image_model'] ?? '');
                $estimated_usd = AIBlogger_Utils::estimate_usage_cost_usd($usage_for_cost, $model_for_cost);

                $detail_params = $base_params;
                $detail_params['aib_posts_page'] = $posts_page;
                $detail_params['aib_post_detail'] = $pid;
                $detail_url = add_query_arg($detail_params, admin_url('admin.php'));

                echo '<tr>';
                echo '<td><a href="' . esc_url(get_edit_post_link($pid, '')) . '">' . esc_html(get_the_title()) . '</a></td>';
                echo '<td>' . esc_html(get_the_date('Y-m-d H:i')) . '</td>';
                echo '<td>' . esc_html((string)((int)($m['total_tokens'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)((int)($m['text_total_tokens'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)((int)($m['image_total_tokens'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)((int)($m['image_calls'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)((int)($m['extra_total_tokens'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)((int)($m['prompt_tokens'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)((int)($m['output_tokens'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)((int)($m['tool_tokens'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)((int)($m['thought_tokens'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)((int)($m['cached_tokens'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)($m['text_model'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)($m['image_model'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)$estimated_usd) . '</td>';
                echo '<td>' . esc_html((string)((int)($ws['attempts'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string)((float)($ws['total_seconds'] ?? 0))) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url($detail_url) . '">Ver</a></td>';
                echo '</tr>';
            }
            wp_reset_postdata();
            ?>
            </tbody>
          </table>
        </div>

        <?php
        $total_pages = max(1, (int)$q->max_num_pages);
        if ($total_pages > 1) :
            $prev_url = '';
            $next_url = '';
            if ($posts_page > 1) {
                $prev_params = $base_params;
                $prev_params['aib_posts_page'] = $posts_page - 1;
                if ($selected_post_id > 0) $prev_params['aib_post_detail'] = $selected_post_id;
                $prev_url = add_query_arg($prev_params, admin_url('admin.php'));
            }
            if ($posts_page < $total_pages) {
                $next_params = $base_params;
                $next_params['aib_posts_page'] = $posts_page + 1;
                if ($selected_post_id > 0) $next_params['aib_post_detail'] = $selected_post_id;
                $next_url = add_query_arg($next_params, admin_url('admin.php'));
            }
        ?>
          <div class="aib-actions" style="margin-top:12px; display:flex; gap:8px; align-items:center;">
            <?php if ($prev_url) : ?><a class="button" href="<?php echo esc_url($prev_url); ?>">&laquo; Anterior</a><?php endif; ?>
            <span>P&aacute;gina <?php echo esc_html((string)$posts_page); ?> de <?php echo esc_html((string)$total_pages); ?></span>
            <?php if ($next_url) : ?><a class="button" href="<?php echo esc_url($next_url); ?>">Siguiente &raquo;</a><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <?php if ($selected_post_id > 0) : ?>
    <section class="aib-card">
      <h2>Detalle de acciones del post #<?php echo esc_html((string)$selected_post_id); ?></h2>
      <?php
      $detail_post = get_post($selected_post_id);
      if (!$detail_post) {
          echo '<p>El post seleccionado no existe.</p>';
      } elseif (empty($post_detail_metrics) || !is_array($post_detail_metrics)) {
          echo '<p>Este post no tiene m&eacute;tricas registradas.</p>';
      } else {
          $calls = is_array($post_detail_metrics['calls'] ?? null) ? $post_detail_metrics['calls'] : [];
          if (empty($calls)) {
              echo '<p>No hay acciones internas registradas para este post.</p>';
          } else {
              $image_calls_detail = (int)($post_detail_metrics['image_calls'] ?? 0);
              echo '<p class="description">Post: <a href="' . esc_url(get_edit_post_link($selected_post_id, '')) . '">' . esc_html(get_the_title($selected_post_id)) . '</a></p>';
              echo '<p class="description">Cantidad de imagenes generadas: <strong>' . esc_html((string)$image_calls_detail) . '</strong></p>';
              echo '<div class="aib-table-wrap">';
              echo '<table class="widefat striped">';
              echo '<thead><tr><th>Fecha</th><th>Acci&oacute;n</th><th>Tipo</th><th>Modelo</th><th>Total</th><th>Prompt</th><th>Salida</th><th>Tool</th><th>Thought</th><th>Cached</th></tr></thead><tbody>';
              foreach ($calls as $c) {
                  if (!is_array($c)) continue;
                  $u = is_array($c['usage'] ?? null) ? $c['usage'] : [];
                  $ts = (int)($c['ts'] ?? 0);
                  echo '<tr>';
                  echo '<td>' . esc_html($ts > 0 ? date_i18n('Y-m-d H:i', $ts) : '-') . '</td>';
                  echo '<td>' . esc_html((string)($c['label'] ?? '')) . '</td>';
                  echo '<td>' . esc_html((string)($c['type'] ?? '')) . '</td>';
                  echo '<td>' . esc_html((string)($c['model'] ?? '')) . '</td>';
                  echo '<td>' . esc_html((string)((int)($u['total_tokens'] ?? 0))) . '</td>';
                  echo '<td>' . esc_html((string)((int)($u['prompt_tokens'] ?? 0))) . '</td>';
                  echo '<td>' . esc_html((string)((int)($u['output_tokens'] ?? 0))) . '</td>';
                  echo '<td>' . esc_html((string)((int)($u['tool_tokens'] ?? 0))) . '</td>';
                  echo '<td>' . esc_html((string)((int)($u['thought_tokens'] ?? 0))) . '</td>';
                  echo '<td>' . esc_html((string)((int)($u['cached_tokens'] ?? 0))) . '</td>';
                  echo '</tr>';
              }
              echo '</tbody></table></div>';
          }
      }
      ?>
    </section>
    <?php endif; ?>
  </div>
</div>
