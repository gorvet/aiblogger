<?php
defined('ABSPATH') || exit;

/** @var array $opts */
/** @var array $text_models */
/** @var array $image_models */
?>
<div class="wrap aiblogger-wrap">
  <section class="aib-page-head">
    <h1>AIBlogger - Generar Art&iacute;culo</h1>
    <p>Genera un borrador SEO con Gemini, im&aacute;genes y enlaces en una sola ejecuci&oacute;n.</p>
  </section>

  <div class="aib-grid">
    <section class="aib-card">
      <h2>Contenido</h2>
      <div class="aib-fields">
        <div class="aib-field">
          <label for="aib_topic">Tem&aacute;tica del art&iacute;culo</label>
          <textarea id="aib_topic" rows="3" maxlength="300" placeholder="M&aacute;ximo 300 caracteres."></textarea>
          <div class="aib-counter"><span id="aib_topic_count">0</span>/300</div>
        </div>

        <div class="aib-field">
          <label for="aib_extra">Instrucciones extra</label>
          <textarea id="aib_extra" rows="5" maxlength="9000" placeholder="Opcional. Aqu&iacute; puedes incluir URLs externas permitidas y reglas espec&iacute;ficas."></textarea>
          <small>Ejemplo: "Cuando menciones WordPress usa https://wordpress.org".</small>
        </div>

        <div class="aib-field">
          <label>Documentos desde Medios</label>
          <input type="hidden" id="aib_media_ids" value="" />
          <div class="aib-actions">
            <button type="button" class="button" id="aib_pick_media_docs">Seleccionar documentos</button>
            <button type="button" class="button button-secondary" id="aib_clear_media_docs">Limpiar</button>
          </div>
          <p id="aib_media_list" class="description">Sin documentos seleccionados.</p>
          <small>Selecci&oacute;n m&uacute;ltiple desde Biblioteca de Medios. TXT/MD/CSV/JSON se leen completos; PDF/DOC/DOCX se procesan en modo best-effort para extraer texto.</small>
        </div>
      </div>
    </section>

    <section class="aib-card">
      <h2>Modelo y Publicaci&oacute;n</h2>
      <div class="aib-fields">
        <div class="aib-field third">
          <label for="aib_language">Idioma</label>
          <?php AIBlogger_Admin::render_language_select('aib_language', (string)($opts['default_language'] ?? 'es'), 'aib_language'); ?>
        </div>

        <div class="aib-field third">
          <label for="aib_text_model">Modelo de texto</label>
          <?php AIBlogger_Admin::render_model_select('aib_text_model', (string)($opts['text_model'] ?? ''), $text_models, 'aib_text_model'); ?>
        </div>

        <div class="aib-field third">
          <label for="aib_image_model">Modelo de imagen</label>
          <?php AIBlogger_Admin::render_model_select('aib_image_model', (string)($opts['image_model'] ?? ''), $image_models, 'aib_image_model'); ?>
        </div>

        <div class="aib-field third">
          <label for="aib_min_words">M&iacute;nimo de palabras</label>
          <input type="number" id="aib_min_words" value="<?php echo esc_attr((int)($opts['min_words'] ?? 800)); ?>" min="100" />
        </div>

        <div class="aib-field third">
          <label for="aib_max_words">M&aacute;ximo de palabras</label>
          <input type="number" id="aib_max_words" value="<?php echo esc_attr((int)($opts['max_words'] ?? 1500)); ?>" min="100" />
        </div>

        <div class="aib-field third">
          <label for="aib_post_type">Tipo de contenido</label>
          <?php AIBlogger_Admin::render_post_type_select('aib_post_type', (string)($opts['post_type'] ?? 'post'), 'aib_post_type'); ?>
        </div>

        <div class="aib-field third">
          <label for="aib_post_status">Estado</label>
          <?php AIBlogger_Admin::render_status_select('aib_post_status', (string)($opts['post_status'] ?? 'draft'), 'aib_post_status'); ?>
        </div>
      </div>
    </section>

    <section class="aib-card">
      <h2>Im&aacute;genes</h2>
      <div class="aib-fields">
        <div class="aib-field half">
          <label class="aib-switch"><input type="checkbox" id="aib_gen_featured" <?php checked(1, (int)($opts['generate_featured'] ?? 1)); ?> /> Generar imagen destacada</label>
          <label for="aib_featured_ar">Aspect ratio destacada</label>
          <?php AIBlogger_Admin::render_aspect_select('aib_featured_ar', (string)($opts['featured_aspect_ratio'] ?? '16:9'), 'aib_featured_ar'); ?>
        </div>

        <div class="aib-field half">
          <label class="aib-switch"><input type="checkbox" id="aib_gen_internal" <?php checked(1, (int)($opts['generate_internal_images'] ?? 0)); ?> /> Generar im&aacute;genes internas</label>
          <div class="aib-inline">
            <div class="aib-field" style="margin:0; flex:1; min-width:120px;">
              <label for="aib_internal_count">Cantidad</label>
              <input type="number" id="aib_internal_count" value="<?php echo esc_attr((int)($opts['internal_images_count'] ?? 2)); ?>" min="0" max="10" />
            </div>
            <div class="aib-field" style="margin:0; flex:1; min-width:160px;">
              <label for="aib_internal_ar">Aspect ratio interna</label>
              <?php AIBlogger_Admin::render_aspect_select('aib_internal_ar', (string)($opts['internal_aspect_ratio'] ?? '4:5'), 'aib_internal_ar'); ?>
            </div>
          </div>
        </div>

        <div class="aib-field third">
          <label for="aib_image_size">Resoluci&oacute;n solicitada</label>
          <?php AIBlogger_Admin::render_image_size_select('aib_image_size', (string)($opts['image_size'] ?? '1K'), 'aib_image_size'); ?>
        </div>
      </div>

      <div class="aib-status-line">
        <button type="button" class="button button-primary" id="aib_generate_btn">Generar art&iacute;culo</button>
        <button type="button" class="button" id="aib_clear_btn">Limpiar</button>
        <span class="aiblogger-status" id="aib_generate_status"></span>
      </div>
      <p class="description">Los l&iacute;mites y el enlazado interno/externo se configuran en <a href="<?php echo esc_url(admin_url('admin.php?page=aiblogger_settings')); ?>">Configuraci&oacute;n</a>.</p>
    </section>
  </div>
</div>
