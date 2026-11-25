<?php
defined('ABSPATH') || exit;

/** @var array $opts */
/** @var array $text_models */
/** @var array $image_models */
?>
<div class="wrap aiblogger-wrap">
  <section class="aib-page-head">
    <h1>AIBlogger - Configuraci&oacute;n</h1>
    <p>Ajusta modelos, publicaci&oacute;n, im&aacute;genes, enlazado y presupuesto global de tokens.</p>
  </section>

  <div class="aiblogger-actions">
    <button type="button" class="button" id="aiblogger-ai-context">Generar contexto con IA (home + about)</button>
    <button type="button" class="button button-secondary" id="aiblogger-reset-defaults">Restablecer valores</button>
    <span class="aiblogger-status" id="aiblogger-settings-status"></span>
  </div>

  <form method="post" action="options.php">
    <?php settings_fields('aiblogger_settings_group'); ?>

    <div class="aib-grid">
      <section class="aib-card">
        <h2>General</h2>
        <div class="aib-fields">
          <div class="aib-field">
            <label for="aib_api_key">API Key (Gemini)</label>
            <input type="password" id="aib_api_key" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[api_key]" value="<?php echo esc_attr($opts['api_key'] ?? ''); ?>" autocomplete="off" />
            <small>No se expone al navegador. Se usa solo en el servidor.</small>
          </div>

          <div class="aib-field third">
            <label for="aib_text_model">Modelo de texto</label>
            <?php AIBlogger_Admin::render_model_select(AIBLOGGER_OPT_KEY . '[text_model]', (string)($opts['text_model'] ?? ''), $text_models, 'aib_text_model'); ?>
          </div>

          <div class="aib-field third">
            <label for="aib_image_model">Modelo de imagen</label>
            <?php AIBlogger_Admin::render_model_select(AIBLOGGER_OPT_KEY . '[image_model]', (string)($opts['image_model'] ?? ''), $image_models, 'aib_image_model'); ?>
            <small>Solo Nano Banana (Gemini Flash Image / Pro Image).</small>
          </div>

          <div class="aib-field third">
            <label for="aib_default_language">Idioma por defecto</label>
            <?php AIBlogger_Admin::render_language_select(AIBLOGGER_OPT_KEY . '[default_language]', (string)($opts['default_language'] ?? 'es'), 'aib_default_language'); ?>
          </div>

          <div class="aib-field">
            <label for="aib_site_context">Contexto del negocio/sitio</label>
            <textarea id="aib_site_context" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[site_context]" rows="7" placeholder="Ejemplo: qui&eacute;nes son, qu&eacute; hacen, p&uacute;blico objetivo, tono, estilo y ubicaci&oacute;n."><?php echo esc_textarea((string)($opts['site_context'] ?? '')); ?></textarea>
            <small>Si lo dejas vac&iacute;o, puedes generarlo con IA desde home + qui&eacute;nes somos.</small>
          </div>
        </div>
      </section>

      <section class="aib-card">
        <h2>Publicaci&oacute;n y Enlaces</h2>
        <div class="aib-fields">
          <div class="aib-field third">
            <label>Rango de palabras</label>
            <div class="aib-inline">
              <input type="number" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[min_words]" value="<?php echo esc_attr((int)($opts['min_words'] ?? 800)); ?>" min="100" />
              <span>-</span>
              <input type="number" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[max_words]" value="<?php echo esc_attr((int)($opts['max_words'] ?? 1500)); ?>" min="100" />
            </div>
          </div>

          <div class="aib-field third">
            <label>Tipo de contenido por defecto</label>
            <?php AIBlogger_Admin::render_post_type_select(AIBLOGGER_OPT_KEY . '[post_type]', (string)($opts['post_type'] ?? 'post')); ?>
          </div>

          <div class="aib-field third">
            <label>Estado por defecto</label>
            <?php AIBlogger_Admin::render_status_select(AIBLOGGER_OPT_KEY . '[post_status]', (string)($opts['post_status'] ?? 'draft')); ?>
          </div>

          <div class="aib-field half">
            <label class="aib-switch"><input type="checkbox" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[enable_internal_links]" value="1" <?php checked(1, (int)($opts['enable_internal_links'] ?? 1)); ?> /> Permitir enlazado interno</label>
            <label>M&aacute;ximo de enlaces internos</label>
            <input type="number" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[internal_links_max]" value="<?php echo esc_attr((int)($opts['internal_links_max'] ?? 3)); ?>" min="0" max="6" />
          </div>

          <div class="aib-field half">
            <label class="aib-switch"><input type="checkbox" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[enable_external_links]" value="1" <?php checked(1, (int)($opts['enable_external_links'] ?? 1)); ?> /> Permitir enlazado externo</label>
            <label>M&aacute;ximo de enlaces externos</label>
            <input type="number" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[external_links_max]" value="<?php echo esc_attr((int)($opts['external_links_max'] ?? 2)); ?>" min="0" max="6" />
            <small>El modelo no debe inventar URLs: solo usa referencias detectadas en instrucciones extra.</small>
          </div>
        </div>
      </section>

      <section class="aib-card">
        <h2>Im&aacute;genes</h2>
        <div class="aib-fields">
          <div class="aib-field half">
            <label class="aib-switch"><input type="checkbox" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[generate_featured]" value="1" <?php checked(1, (int)($opts['generate_featured'] ?? 1)); ?> /> Generar imagen destacada</label>
            <label>Aspect ratio destacada</label>
            <?php AIBlogger_Admin::render_aspect_select(AIBLOGGER_OPT_KEY . '[featured_aspect_ratio]', (string)($opts['featured_aspect_ratio'] ?? '16:9')); ?>
          </div>

          <div class="aib-field half">
            <label class="aib-switch"><input type="checkbox" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[generate_internal_images]" value="1" <?php checked(1, (int)($opts['generate_internal_images'] ?? 0)); ?> /> Generar im&aacute;genes internas</label>
            <label>Cantidad</label>
            <input type="number" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[internal_images_count]" value="<?php echo esc_attr((int)($opts['internal_images_count'] ?? 2)); ?>" min="0" max="10" />
            <label>Aspect ratio internas</label>
            <?php AIBlogger_Admin::render_aspect_select(AIBLOGGER_OPT_KEY . '[internal_aspect_ratio]', (string)($opts['internal_aspect_ratio'] ?? '4:5')); ?>
          </div>

          <div class="aib-field third">
            <label>Resoluci&oacute;n solicitada</label>
            <?php AIBlogger_Admin::render_image_size_select(AIBLOGGER_OPT_KEY . '[image_size]', (string)($opts['image_size'] ?? '1K')); ?>
          </div>

          <div class="aib-field third">
            <label>Tama&ntilde;o al insertar</label>
            <?php AIBlogger_Admin::render_image_display_size_select(AIBLOGGER_OPT_KEY . '[internal_image_display_size]', (string)($opts['internal_image_display_size'] ?? 'large')); ?>
          </div>

          <div class="aib-field third">
            <small style="margin-top:28px;">Si el modelo no soporta el ratio elegido, AIBlogger lo degrada al m&aacute;s cercano.</small>
          </div>
        </div>
      </section>

      <section class="aib-card">
        <h2>Presupuesto Global de Tokens</h2>
        <div class="aib-fields">
          <div class="aib-field third">
            <label>L&iacute;mite diario</label>
            <input type="number" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[tokens_daily_limit]" value="<?php echo esc_attr((int)($opts['tokens_daily_limit'] ?? 0)); ?>" min="0" step="5000" />
          </div>

          <div class="aib-field third">
            <label>L&iacute;mite mensual</label>
            <input type="number" name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[tokens_monthly_limit]" value="<?php echo esc_attr((int)($opts['tokens_monthly_limit'] ?? 0)); ?>" min="0" step="50000" />
          </div>

          <div class="aib-field third">
            <label>Acci&oacute;n al exceder</label>
            <?php $mode = (string)($opts['tokens_limit_mode'] ?? 'warn'); ?>
            <select name="<?php echo esc_attr(AIBLOGGER_OPT_KEY); ?>[tokens_limit_mode]">
              <option value="warn" <?php selected($mode, 'warn'); ?>>Solo warning (permite continuar)</option>
              <option value="block" <?php selected($mode, 'block'); ?>>Bloquear nuevas generaciones</option>
            </select>
          </div>

          <div class="aib-field">
            <small>Usa 0 para dejar un l&iacute;mite sin restricci&oacute;n. El presupuesto aplica al sitio completo, no por usuario.</small>
          </div>
        </div>
      </section>
    </div>

    <?php submit_button('Guardar configuraci&oacute;n'); ?>
  </form>
</div>
