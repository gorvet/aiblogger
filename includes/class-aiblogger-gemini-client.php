<?php
defined('ABSPATH') || exit;

class AIBlogger_Gemini_Client {

    private string $api_key;
    private string $base;

    /**
     * Default timeout (seconds) for API calls.
     */
    private int $timeout_default = 240;
    private int $timeout_max = 720;
    private int $retry_attempts = 1;

    public function __construct(string $api_key) {
        $this->api_key = trim($api_key);
        $this->base = 'https://generativelanguage.googleapis.com/v1beta';

        $configured = (int)apply_filters('aiblogger_http_timeout', $this->timeout_default);
        $this->timeout_default = max(30, min($configured, $this->timeout_max));
    }

    private function headers(): array {
        return [
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $this->api_key,
        ];
    }

    private function request(string $method, string $url, ?array $body = null, ?int $timeout = null, int $attempt = 0) {
        if (!$this->api_key) {
            return new WP_Error('aiblogger_no_key', 'Falta la API Key de Gemini.');
        }

        $timeout_sec = $timeout ? (int)$timeout : $this->timeout_default;
        $timeout_sec = max(15, min($timeout_sec, $this->timeout_max));

        $args = [
            'method' => $method,
            'timeout' => $timeout_sec,
            'headers' => $this->headers(),
        ];
        if (!is_null($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            if ($attempt < $this->retry_attempts && $this->should_retry_wp_error($res)) {
                usleep(300000);
                $retry_timeout = (int)min($this->timeout_max, max($timeout_sec + 30, round($timeout_sec * 1.7)));
                return $this->request($method, $url, $body, $retry_timeout, $attempt + 1);
            }

            $msg = (string)$res->get_error_message();
            if (stripos($msg, 'timed out') !== false || stripos($msg, 'cURL error 28') !== false) {
                return new WP_Error('aiblogger_timeout', 'Tiempo de espera agotado al llamar a Gemini. Prueba un modelo mas rapido o aumenta limites del servidor (PHP max_execution_time).', [
                    'original' => $res,
                    'url' => $url,
                    'timeout' => $timeout_sec,
                ]);
            }
            return $res;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);
        $json = null;
        if ($raw !== '') {
            $json = json_decode($raw, true);
        }

        if ($code < 200 || $code >= 300) {
            if ($attempt < $this->retry_attempts && $this->should_retry_http_code($code)) {
                usleep(300000);
                $retry_timeout = (int)min($this->timeout_max, max($timeout_sec + 30, round($timeout_sec * 1.4)));
                return $this->request($method, $url, $body, $retry_timeout, $attempt + 1);
            }

            $msg = 'Error HTTP ' . $code;
            if (is_array($json)) {
                $msg2 = $json['error']['message'] ?? $json['message'] ?? '';
                if ($msg2) $msg .= ': ' . $msg2;
            }
            return new WP_Error('aiblogger_http_error', $msg, ['code' => $code, 'body' => $raw, 'json' => $json]);
        }

        return is_array($json) ? $json : ['raw' => $raw];
    }

    private function should_retry_http_code(int $code): bool {
        return in_array($code, [408, 425, 429, 500, 502, 503, 504], true);
    }

    private function should_retry_wp_error(WP_Error $err): bool {
        $msg = strtolower((string)$err->get_error_message());
        if ($msg === '') return false;

        $signals = [
            'timed out',
            'curl error 28',
            'curl error 6',
            'curl error 7',
            'temporarily unavailable',
            'connection reset',
            'ssl connect error',
            'empty reply from server',
            'could not resolve host',
        ];
        foreach ($signals as $s) {
            if (strpos($msg, $s) !== false) return true;
        }
        return false;
    }

    private function timeout_for_model(string $model, string $op = 'text'): int {
        $m = strtolower(trim($model));
        $timeout = $this->timeout_default;

        if (strpos($m, 'pro') !== false) {
            $timeout += 120;
        } elseif (strpos($m, '3.1') !== false || strpos($m, '2.5') !== false) {
            $timeout += 60;
        }

        if ($op === 'structured') {
            $timeout += 90;
        } elseif ($op === 'image') {
            $timeout += 150;
        }

        return max(30, min($timeout, $this->timeout_max));
    }

    public function list_models() {
        $url = $this->base . '/models';
        $out = [];
        $page_token = null;
        $loops = 0;
        do {
            $loops++;
            $q = $url;
            if ($page_token) {
                $q = add_query_arg(['pageToken' => $page_token], $url);
            }

            $res = $this->request('GET', $q, null);
            if (is_wp_error($res)) return $res;

            $models = $res['models'] ?? [];
            if (is_array($models)) {
                foreach ($models as $m) {
                    $name = $m['name'] ?? '';
                    $base = $m['baseModelId'] ?? '';
                    $disp = $m['displayName'] ?? $base;
                    $methods = $m['supportedGenerationMethods'] ?? [];
                    if (!$name) continue;

                    $id = str_replace('models/', '', $name);

                    $out[] = [
                        'id' => $id,
                        'label' => $disp ?: $id,
                        'baseModelId' => $base,
                        'methods' => is_array($methods) ? $methods : [],
                    ];
                }
            }

            $page_token = $res['nextPageToken'] ?? null;
        } while ($page_token && $loops < 5);

        return $out;
    }

    public function generate_text(string $model, string $prompt, array $opts = []) {
        $url = $this->base . '/models/' . rawurlencode($model) . ':generateContent';

        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
        ];

        if (!empty($opts)) {
            $body['generationConfig'] = [];
            if (isset($opts['temperature'])) $body['generationConfig']['temperature'] = (float)$opts['temperature'];
        }

        $res = $this->request('POST', $url, $body, $this->timeout_for_model($model, 'text'));
        if (is_wp_error($res)) return $res;
        return (string)$this->extract_text($res);
    }

    public function generate_text_with_usage(string $model, string $prompt, array $opts = []) {
        $url = $this->base . '/models/' . rawurlencode($model) . ':generateContent';

        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
        ];

        if (!empty($opts)) {
            $body['generationConfig'] = [];
            if (isset($opts['temperature'])) $body['generationConfig']['temperature'] = (float)$opts['temperature'];
        }

        $res = $this->request('POST', $url, $body, $this->timeout_for_model($model, 'text'));
        if (is_wp_error($res)) return $res;

        return [
            'text' => (string)$this->extract_text($res),
            'raw' => $res,
            'usage' => $res['usageMetadata'] ?? [],
        ];
    }

    public function generate_structured(string $model, string $prompt, array $json_schema, array $opts = []) {
        $url = $this->base . '/models/' . rawurlencode($model) . ':generateContent';

        $body = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $json_schema,
            ],
        ];

        if (isset($opts['temperature'])) {
            $body['generationConfig']['temperature'] = (float)$opts['temperature'];
        }

        $res = $this->request('POST', $url, $body, $this->timeout_for_model($model, 'structured'));
        if (is_wp_error($res)) {
            return $this->generate_structured_fallback($model, $prompt, $json_schema, $opts);
        }

        $text = $this->extract_text($res);
        $data = json_decode((string)$text, true);
        if (!is_array($data)) {
            return $this->generate_structured_fallback($model, $prompt, $json_schema, $opts, $res);
        }

        return [
            'data' => $data,
            'raw' => $res,
            'usage' => $res['usageMetadata'] ?? [],
        ];
    }

    private function generate_structured_fallback(string $model, string $prompt, array $json_schema, array $opts = [], ?array $already = null) {
        $url = $this->base . '/models/' . rawurlencode($model) . ':generateContent';

        $schema_hint = wp_json_encode($json_schema);
        $fallback_prompt = $prompt . "\n\nDEVUELVE SOLO JSON VALIDO (sin markdown) que cumpla este schema: " . $schema_hint;

        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $fallback_prompt]],
            ]],
        ];

        if (isset($opts['temperature'])) {
            $body['generationConfig']['temperature'] = (float)$opts['temperature'];
        }

        $res = $this->request('POST', $url, $body, $this->timeout_for_model($model, 'structured'));
        if (is_wp_error($res)) return $res;

        $text = (string) $this->extract_text($res);
        $json = $this->extract_json_from_text($text);
        $data = $json ? json_decode($json, true) : null;

        if (!is_array($data)) {
            return new WP_Error('aiblogger_bad_json', 'El modelo no devolvio JSON valido.', ['text' => $text, 'raw' => $res, 'prior' => $already]);
        }

        return [
            'data' => $data,
            'raw' => $res,
            'usage' => $res['usageMetadata'] ?? [],
        ];
    }

    private function extract_json_from_text(string $text): ?string {
        $t = trim($text);
        if ($t && ($t[0] === '{' || $t[0] === '[')) return $t;

        $start = strpos($t, '{');
        $end   = strrpos($t, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        return substr($t, $start, $end - $start + 1);
    }

    private function extract_text(array $res): string {
        $candidates = $res['candidates'] ?? [];
        if (!is_array($candidates) || empty($candidates[0])) return '';
        $parts = $candidates[0]['content']['parts'] ?? [];
        if (!is_array($parts)) return '';
        $out = '';
        foreach ($parts as $p) {
            if (isset($p['text'])) $out .= (string)$p['text'];
        }
        return $out;
    }

    public function generate_images(string $model, string $prompt, array $config = []) {
        if (strpos($model, 'imagen-') === 0) {
            return new WP_Error('aiblogger_model_not_supported', 'Imagen 4 no esta habilitado en este plugin. Usa Nano Banana.', ['model' => $model]);
        }
        return $this->generate_images_gemini_generatecontent($model, $prompt, $config);
    }

    private function generate_images_gemini_generatecontent(string $model, string $prompt, array $config) {
        $url = $this->base . '/models/' . rawurlencode($model) . ':generateContent';

        $gen = [
            'responseModalities' => ['IMAGE'],
        ];

        $image_config = [];
        if (!empty($config['aspectRatio'])) $image_config['aspectRatio'] = (string)$config['aspectRatio'];
        if (!empty($config['imageSize'])) $image_config['imageSize'] = (string)$config['imageSize'];
        if ($image_config) $gen['imageConfig'] = $image_config;

        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => $gen,
        ];

        $res = $this->request('POST', $url, $body, $this->timeout_for_model($model, 'image'));
        if (is_wp_error($res)) {
            if (isset($body['generationConfig']['imageConfig'])) {
                unset($body['generationConfig']['imageConfig']);
                $res2 = $this->request('POST', $url, $body, $this->timeout_for_model($model, 'image'));
                if (!is_wp_error($res2)) {
                    $res = $res2;
                } else {
                    return $res;
                }
            } else {
                return $res;
            }
        }

        $parts = $res['candidates'][0]['content']['parts'] ?? [];
        $images = [];
        if (is_array($parts)) {
            foreach ($parts as $p) {
                if (!empty($p['inlineData']['data'])) {
                    $b64 = (string)$p['inlineData']['data'];
                    $mime = (string)($p['inlineData']['mimeType'] ?? 'image/png');
                    $bytes = base64_decode($b64);
                    if ($bytes !== false) {
                        $images[] = ['bytes' => $bytes, 'mime' => $mime];
                    }
                }
            }
        }

        if (!$images) {
            $found = $this->extract_image_from_any($res);
            if ($found) $images[] = $found;
        }

        if (!$images) {
            return new WP_Error('aiblogger_no_image', 'No se pudo extraer imagen de la respuesta del modelo.', ['raw' => $res]);
        }

        return [
            'images' => $images,
            'usage' => $res['usageMetadata'] ?? [],
        ];
    }

    private function extract_image_from_any($node): ?array {
        if (is_array($node)) {
            if (!empty($node['inlineData']['data'])) {
                $bytes = base64_decode((string)$node['inlineData']['data']);
                if ($bytes !== false) {
                    $mime = (string)($node['inlineData']['mimeType'] ?? 'image/png');
                    return ['bytes' => $bytes, 'mime' => $mime];
                }
            }

            foreach (['imageBytes', 'bytesBase64Encoded', 'bytes_base64_encoded', 'data'] as $k) {
                if (!empty($node[$k]) && is_string($node[$k])) {
                    $bytes = base64_decode($node[$k]);
                    if ($bytes !== false && strlen($bytes) > 100) {
                        $mime = (string)($node['mimeType'] ?? $node['mime_type'] ?? AIBlogger_Utils::guess_mime_from_bytes($bytes));
                        return ['bytes' => $bytes, 'mime' => $mime];
                    }
                }
            }

            foreach ($node as $v) {
                $found = $this->extract_image_from_any($v);
                if ($found) return $found;
            }
        }
        return null;
    }
}
