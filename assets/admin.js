(function($){
  function setStatus($el, msg, type){
    if(!$el || !$el.length) return;
    $el.removeClass('ok err');
    if(type==='ok') $el.addClass('ok');
    if(type==='err') $el.addClass('err');
    $el.text(msg || '');
  }

  function initCounter(){
    var $t = $('#aib_topic');
    if(!$t.length) return;
    var $c = $('#aib_topic_count');
    var update = function(){ $c.text(($t.val()||'').length); };
    $t.on('input', update);
    update();
  }

  function gatherGeneratePayload(){
    return {
      action: 'aiblogger_generate',
      nonce: AIBLOGGER.nonce,
      topic: $('#aib_topic').val() || '',
      extra: $('#aib_extra').val() || '',
      media_ids: $('#aib_media_ids').val() || '',
      language: $('#aib_language').val() || 'es',
      text_model: $('#aib_text_model').val() || '',
      image_model: $('#aib_image_model').val() || '',
      min_words: $('#aib_min_words').val() || '',
      max_words: $('#aib_max_words').val() || '',
      post_type: $('#aib_post_type').val() || 'post',
      post_status: $('#aib_post_status').val() || 'draft',
      gen_featured: $('#aib_gen_featured').is(':checked') ? 1 : 0,
      featured_ar: $('#aib_featured_ar').val() || '16:9',
      gen_internal: $('#aib_gen_internal').is(':checked') ? 1 : 0,
      internal_count: $('#aib_internal_count').val() || 0,
      internal_ar: $('#aib_internal_ar').val() || '4:5',
      image_size: $('#aib_image_size').val() || '1K'
    };
  }

  function clearGenerateForm(){
    $('#aib_topic').val('');
    $('#aib_extra').val('');
    $('#aib_media_ids').val('');
    $('#aib_media_list').text('Sin documentos seleccionados.');
    $('#aib_topic').trigger('input');
  }

  function errorMessageFromResponse(resp, xhr){
    var m = 'Error.';
    var xhrStatus = xhr && xhr.status ? parseInt(xhr.status, 10) : 0;

    function isHtmlLike(txt){
      var normalized = String(txt || '').trim().toLowerCase();
      if(!normalized) return false;
      return normalized.indexOf('<!doctype html') !== -1
        || normalized.indexOf('<html') !== -1
        || normalized.indexOf('<head') !== -1
        || normalized.indexOf('<body') !== -1
        || normalized.indexOf('<title>502 bad gateway') !== -1
        || normalized.indexOf('<title>504 gateway timeout') !== -1;
    }

    function isGatewayLike(txt){
      var normalized = String(txt || '').trim().toLowerCase();
      if(!normalized) return false;
      return normalized.indexOf('gateway error') !== -1
        || normalized.indexOf('bad gateway') !== -1
        || normalized.indexOf('gateway timeout') !== -1
        || normalized.indexOf('server has timed out') !== -1;
    }

    function gatewayMessage(){
      return 'El servidor intermedio corto la peticion antes de que WordPress devolviera JSON. La generacion esta tardando demasiado: prueba un modelo mas rapido, menos imagenes o revisa los timeouts de Nginx/Apache/Cloudflare/PHP.';
    }

    if(resp && resp.data && resp.data.message){
      m = resp.data.message;
      if(resp.data.details && resp.data.details.timeout){
        m += ' (timeout ' + resp.data.details.timeout + 's)';
      }
      return m;
    }
    if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
      m = xhr.responseJSON.data.message;
      return m;
    }
    if(xhrStatus === 502 || xhrStatus === 504){
      return gatewayMessage();
    }
    if(xhr && xhr.responseText){
      var txt = String(xhr.responseText).trim();
      if(txt === '-1'){
        return 'Sesion expirada o nonce invalido. Recarga la pagina y vuelve a intentar.';
      }
      if(txt){
        if(isHtmlLike(txt) || isGatewayLike(txt)){
          return isGatewayLike(txt) ? gatewayMessage() : 'El servidor devolvio HTML en lugar de JSON. Revisa el log de PHP y el proxy/webserver para ver el error exacto.';
        }
        return txt.slice(0, 240);
      }
    }
    return m;
  }

  function bindMediaPicker(){
    var $pick = $('#aib_pick_media_docs');
    if(!$pick.length) return;
    if(!(window.wp && wp.media)) return;

    var frame = null;

    function clearSelection(){
      $('#aib_media_ids').val('');
      $('#aib_media_list').text('Sin documentos seleccionados.');
    }

    $('#aib_clear_media_docs').on('click', function(){
      clearSelection();
      setStatus($('#aib_generate_status'), 'Documentos limpiados.', 'ok');
    });

    $pick.on('click', function(e){
      e.preventDefault();

      if(frame){
        frame.open();
        return;
      }

      frame = wp.media({
        title: 'Seleccionar documentos',
        button: { text: 'Usar documentos' },
        library: { type: ['text/plain','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','text/csv','application/json'] },
        multiple: true
      });

      frame.on('select', function(){
        var selection = frame.state().get('selection');
        var ids = [];
        var names = [];
        selection.each(function(item){
          var a = item.toJSON();
          ids.push(a.id);
          names.push(a.filename || a.title || ('ID ' + a.id));
        });
        $('#aib_media_ids').val(ids.join(','));
        $('#aib_media_list').text(names.length ? names.join(', ') : 'Sin documentos seleccionados.');
        setStatus($('#aib_generate_status'), names.length ? 'Documentos vinculados al prompt.' : 'Sin documentos seleccionados.', names.length ? 'ok' : '');
      });

      frame.open();
    });
  }

  function bindGenerate(){
    var $btn = $('#aib_generate_btn');
    if(!$btn.length) return;

    function collectWarnings(list, extra){
      var out = Array.isArray(list) ? list.slice(0) : [];
      if(Array.isArray(extra)){
        extra.forEach(function(w){
          var txt = String(w || '').trim();
          if(txt) out.push(txt);
        });
      } else {
        var single = String(extra || '').trim();
        if(single) out.push(single);
      }
      return out.filter(function(v, idx, arr){
        return arr.indexOf(v) === idx;
      });
    }

    function phaseLabel(phase){
      var p = String(phase || '').toLowerCase();
      if(p === 'text') return 'Fase 1/3: Generando articulo';
      if(p === 'images') return 'Fase 2/3: Generando imagenes';
      if(p === 'publish') return 'Fase 3/3: Publicando post';
      if(p === 'done') return 'Completado';
      if(p === 'failed') return 'Fallido';
      return 'Procesando';
    }

    function pollStatus(jobToken, warnings, $status){
      $.post(AIBLOGGER.ajaxurl, {
        action: 'aiblogger_generate_status',
        nonce: AIBLOGGER.nonce,
        job_token: jobToken
      })
      .done(function(resp){
        if(!(resp && resp.success && resp.data)){
          setStatus($status, errorMessageFromResponse(resp, null), 'err');
          $btn.prop('disabled', false);
          return;
        }

        var data = resp.data || {};
        var allWarnings = collectWarnings(warnings, data.warnings || []);
        var status = String(data.status || '').toLowerCase();
        var phase = String(data.phase || '').toLowerCase();
        var planned = parseInt(data.planned_images || 0, 10);
        var generated = parseInt(data.generated_images || 0, 10);
        var attempted = parseInt(data.attempted_images || 0, 10);
        var maxAttempts = parseInt(data.max_attempts || 0, 10);
        var attempts = parseInt(data.attempts || 0, 10);

        if(!Number.isFinite(planned) || planned < 0) planned = 0;
        if(!Number.isFinite(generated) || generated < 0) generated = 0;
        if(!Number.isFinite(attempted) || attempted < 0) attempted = 0;
        if(!Number.isFinite(maxAttempts) || maxAttempts < 0) maxAttempts = 0;
        if(!Number.isFinite(attempts) || attempts < 0) attempts = 0;

        if(status === 'done' && parseInt(data.post_id || 0, 10) > 0){
          var msg = 'Listo. Post #' + data.post_id;
          if(generated > 0){
            msg += ' | imagenes: ' + generated;
          }
          if(allWarnings.length){
            msg += ' (warnings: ' + allWarnings.join(' | ').slice(0, 240) + ')';
          }
          setStatus($status, msg, 'ok');

          var editUrl = data.edit_url ? String(data.edit_url) : '';
          if(editUrl && AIBLOGGER.openNewTabDefault){
            window.open(editUrl, '_blank');
          }
          clearGenerateForm();
          $btn.prop('disabled', false);
          return;
        }

        if(status === 'failed'){
          var err = data.last_error ? String(data.last_error) : 'La generacion fallo en segundo plano.';
          if(maxAttempts > 0){
            err += ' (intentos: ' + attempts + '/' + maxAttempts + ')';
          }
          setStatus($status, err, 'err');
          $btn.prop('disabled', false);
          return;
        }

        var base = phaseLabel(phase);
        if(phase === 'images'){
          base += ' (' + attempted + '/' + planned + ')';
        }
        if(status === 'retrying'){
          var reason = data.last_error ? String(data.last_error) : 'error temporal';
          base += ' | reintentando: ' + reason;
          if(maxAttempts > 0){
            base += ' (' + attempts + '/' + maxAttempts + ')';
          }
        }
        setStatus($status, base + '...', '');

        setTimeout(function(){
          pollStatus(jobToken, allWarnings, $status);
        }, status === 'retrying' ? 2500 : 1500);
      })
      .fail(function(xhr){
        setStatus($status, errorMessageFromResponse(null, xhr), 'err');
        $btn.prop('disabled', false);
      });
    }

    $btn.on('click', function(){
      var $status = $('#aib_generate_status');
      setStatus($status, 'Fase 1/3: Generando articulo...', '');
      $btn.prop('disabled', true);

      var payload = gatherGeneratePayload();
      payload.action = 'aiblogger_generate_start';

      $.post(AIBLOGGER.ajaxurl, payload)
        .done(function(resp){
          if(!(resp && resp.success && resp.data && resp.data.job_token)){
            setStatus($status, errorMessageFromResponse(resp, null), 'err');
            $btn.prop('disabled', false);
            return;
          }

          var data = resp.data || {};
          var jobToken = String(data.job_token || '').trim();
          if(!jobToken){
            setStatus($status, 'No se recibio token de generacion.', 'err');
            $btn.prop('disabled', false);
            return;
          }

          var warnings = collectWarnings([], data.warnings || []);
          setStatus($status, 'Trabajo encolado. Procesando en segundo plano...', '');
          pollStatus(jobToken, warnings, $status);
        })
        .fail(function(xhr){
          setStatus($status, errorMessageFromResponse(null, xhr), 'err');
          $btn.prop('disabled', false);
        });
    });

    $('#aib_clear_btn').on('click', function(){
      clearGenerateForm();
      setStatus($('#aib_generate_status'), '', '');
    });
  }

  function bindSettingsActions(){
    var $st = $('#aiblogger-settings-status');
    if(!$st.length) return;

    $('#aiblogger-ai-context').on('click', function(){
      setStatus($st, 'Generando contexto con IA...', '');
      $.post(AIBLOGGER.ajaxurl, {action:'aiblogger_generate_site_context_ai', nonce:AIBLOGGER.nonce})
        .done(function(resp){
          if(resp && resp.success){
            if(resp.data && resp.data.site_context){
              $('#aib_site_context').val(resp.data.site_context);
            }
            var tt = (resp.data && resp.data.usage && resp.data.usage.total_tokens) ? parseInt(resp.data.usage.total_tokens,10) : 0;
            var extra = tt ? (' ('+tt+' tokens)') : '';
            setStatus($st, 'Contexto generado'+extra+'. No olvides guardar la configuraci\u00f3n.', 'ok');
          } else {
            setStatus($st, errorMessageFromResponse(resp, null), 'err');
          }
        })
        .fail(function(xhr){
          setStatus($st, errorMessageFromResponse(null, xhr), 'err');
        });
    });

    $('#aiblogger-reset-defaults').on('click', function(){
      if(!confirm('\\u00bfRestablecer configuraci\\u00f3n a valores por defecto?')) return;
      setStatus($st, 'Restableciendo...', '');
      $.post(AIBLOGGER.ajaxurl, {action:'aiblogger_reset_defaults', nonce:AIBLOGGER.nonce})
        .done(function(resp){
          if(resp && resp.success){
            setStatus($st, 'Restablecido. Recarga la p\\u00e1gina.', 'ok');
          } else {
            setStatus($st, errorMessageFromResponse(resp, null), 'err');
          }
        })
        .fail(function(xhr){
          setStatus($st, errorMessageFromResponse(null, xhr), 'err');
        });
    });
  }

  function getClassicSelection(){
    if(window.tinyMCE && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()){
      return tinyMCE.activeEditor.selection.getContent({format:'text'}) || '';
    }
    var $content = $('#content');
    if(!$content.length) return '';
    var el = $content.get(0);
    if(typeof el.selectionStart === 'number' && el.selectionEnd > el.selectionStart){
      return el.value.substring(el.selectionStart, el.selectionEnd);
    }
    return '';
  }

  function replaceClassicSelection(text){
    if(window.tinyMCE && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()){
      tinyMCE.activeEditor.selection.setContent(text);
      return true;
    }
    var $content = $('#content');
    if(!$content.length) return false;
    var el = $content.get(0);
    if(typeof el.selectionStart === 'number' && el.selectionEnd >= el.selectionStart){
      var before = el.value.substring(0, el.selectionStart);
      var after = el.value.substring(el.selectionEnd);
      el.value = before + text + after;
      return true;
    }
    return false;
  }

  function insertClassicHtml(html){
    if(window.tinyMCE && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()){
      tinyMCE.activeEditor.execCommand('mceInsertContent', false, html);
      return true;
    }
    var $content = $('#content');
    if(!$content.length) return false;
    $content.val(($content.val() || '') + '\n\n' + html);
    return true;
  }

  function getSelectedGutenbergBlock(){
    if(!(window.wp && wp.data && wp.data.select)) return null;
    var select = wp.data.select('core/block-editor');
    if(!select) return null;
    var clientId = select.getSelectedBlockClientId();
    if(!clientId) return null;
    return select.getBlock(clientId) || null;
  }

  function getGutenbergTextForRewrite(){
    var block = getSelectedGutenbergBlock();
    if(!block) return '';
    var attrs = block.attributes || {};
    if(typeof attrs.content === 'string' && attrs.content.trim()) return attrs.content;
    if(typeof attrs.value === 'string' && attrs.value.trim()) return attrs.value;
    if(window.wp && wp.blocks){
      return wp.blocks.serialize([block]);
    }
    return '';
  }

  function applyGutenbergRewrite(newText){
    if(!(window.wp && wp.data && wp.blocks)) return false;
    var select = wp.data.select('core/block-editor');
    var dispatch = wp.data.dispatch('core/block-editor');
    if(!select || !dispatch) return false;

    var clientId = select.getSelectedBlockClientId();
    if(!clientId) return false;

    var block = select.getBlock(clientId);
    if(!block) return false;

    var attrs = block.attributes || {};
    if(Object.prototype.hasOwnProperty.call(attrs, 'content')){
      dispatch.updateBlockAttributes(clientId, {content: newText});
      return true;
    }
    if(Object.prototype.hasOwnProperty.call(attrs, 'value')){
      dispatch.updateBlockAttributes(clientId, {value: newText});
      return true;
    }

    var replacement = wp.blocks.createBlock('core/paragraph', {content: newText});
    dispatch.replaceBlock(clientId, replacement);
    return true;
  }

  function insertOrReplaceGutenbergImage(data){
    if(!(window.wp && wp.data && wp.blocks)) return false;
    var select = wp.data.select('core/block-editor');
    var dispatch = wp.data.dispatch('core/block-editor');
    if(!select || !dispatch) return false;

    var clientId = select.getSelectedBlockClientId();
    var block = clientId ? select.getBlock(clientId) : null;

    var sizeSlug = (data && data.size_slug) ? String(data.size_slug) : '';
    if(!sizeSlug && data && data.url && /-opt\.webp(?:\?|$)/i.test(String(data.url))){
      sizeSlug = 'aiblogger_opt';
    }

    var attrs = {
      id: parseInt(data.attachment_id || 0, 10) || 0,
      url: data.url || '',
      alt: data.alt || '',
      sizeSlug: sizeSlug || undefined
    };

    if(block && block.name === 'core/image'){
      dispatch.updateBlockAttributes(clientId, attrs);
      return true;
    }

    var imageBlock = wp.blocks.createBlock('core/image', attrs);
    var index = (clientId && typeof select.getBlockIndex === 'function') ? select.getBlockIndex(clientId) : -1;
    if(index >= 0){
      dispatch.insertBlocks(imageBlock, index + 1);
    } else {
      dispatch.insertBlocks(imageBlock);
    }
    return true;
  }

  function getCurrentEditorPostId(){
    var raw = $('#post_ID').val();
    var pid = parseInt(raw || '0', 10);
    return Number.isFinite(pid) && pid > 0 ? pid : 0;
  }

  function bindEditorAssistant(){
    var $rewrite = $('#aib_editor_rewrite_btn');
    var $image = $('#aib_editor_image_btn');
    var $status = $('#aib_editor_status');
    if(!$rewrite.length && !$image.length) return;

    $rewrite.on('click', function(){
      var instruction = ($('#aib_editor_instruction').val() || '').trim();
      var text = getGutenbergTextForRewrite() || getClassicSelection();
      if(!text){
        setStatus($status, 'Selecciona un bloque o texto antes de reescribir.', 'err');
        return;
      }

      setStatus($status, 'Reescribiendo...', '');
      $rewrite.prop('disabled', true);

      $.post(AIBLOGGER.ajaxurl, {
        action: 'aiblogger_editor_rewrite',
        nonce: AIBLOGGER.nonce,
        text: text,
        instruction: instruction,
        post_id: getCurrentEditorPostId()
      })
      .done(function(resp){
        if(!(resp && resp.success && resp.data && resp.data.text)){
          setStatus($status, errorMessageFromResponse(resp, null), 'err');
          return;
        }
        var rewritten = resp.data.text;
        var applied = applyGutenbergRewrite(rewritten);
        if(!applied){
          applied = replaceClassicSelection(rewritten);
        }
        if(applied){
          $('#aib_editor_instruction').val('');
          setStatus($status, 'Texto reescrito e insertado.', 'ok');
        } else {
          setStatus($status, 'Se gener\\u00f3 el texto pero no se pudo insertar autom\\u00e1ticamente.', 'err');
        }
      })
      .fail(function(xhr){
        setStatus($status, errorMessageFromResponse(null, xhr), 'err');
      })
      .always(function(){
        $rewrite.prop('disabled', false);
      });
    });

    $image.on('click', function(){
      var scene = ($('#aib_editor_scene').val() || '').trim();
      var alt = ($('#aib_editor_alt').val() || '').trim();
      if(!scene){
        setStatus($status, 'Indica la descripci\\u00f3n de escena para generar la imagen.', 'err');
        return;
      }

      setStatus($status, 'Generando imagen...', '');
      $image.prop('disabled', true);

      $.post(AIBLOGGER.ajaxurl, {
        action: 'aiblogger_editor_image',
        nonce: AIBLOGGER.nonce,
        scene: scene,
        alt: alt,
        post_id: getCurrentEditorPostId()
      })
      .done(function(resp){
        if(!(resp && resp.success && resp.data)){
          setStatus($status, errorMessageFromResponse(resp, null), 'err');
          return;
        }

        var data = resp.data;
        var inserted = insertOrReplaceGutenbergImage(data);
        if(!inserted && data.html){
          inserted = insertClassicHtml(data.html);
        }

        if(inserted){
          $('#aib_editor_scene').val('');
          $('#aib_editor_alt').val('');
          setStatus($status, 'Imagen generada e insertada.', 'ok');
        } else {
          setStatus($status, 'Imagen creada pero no insertada autom\\u00e1ticamente.', 'err');
        }
      })
      .fail(function(xhr){
        setStatus($status, errorMessageFromResponse(null, xhr), 'err');
      })
      .always(function(){
        $image.prop('disabled', false);
      });
    });
  }

  $(function(){
    initCounter();
    bindMediaPicker();
    bindGenerate();
    bindSettingsActions();
    bindEditorAssistant();
  });

})(jQuery);
