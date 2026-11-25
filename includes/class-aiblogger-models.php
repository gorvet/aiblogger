<?php
defined('ABSPATH') || exit;

/**
 * Modelos
 *
 * Usamos listas curadas para UX estable. No incluimos Imagen 4 porque el usuario
 * pidió trabajar solo con Nano Banana.
 */
class AIBlogger_Models {

    /**
     * Lista curada de modelos de texto.
     */
    public static function curated_text_models(): array {
        return [
            ['id' => 'gemini-3.1-pro-preview',   'label' => 'Gemini 3.1 Pro (Preview)'],
            ['id' => 'gemini-3-pro-preview',     'label' => 'Gemini 3 Pro (Preview)'],
            ['id' => 'gemini-3-flash-preview',   'label' => 'Gemini 3 Flash (Preview)'],
            ['id' => 'gemini-2.5-pro',           'label' => 'Gemini 2.5 Pro'],
            ['id' => 'gemini-2.5-flash',         'label' => 'Gemini 2.5 Flash'],
            ['id' => 'gemini-2.5-flash-lite',    'label' => 'Gemini 2.5 Flash-Lite'],
        ];
    }

    /**
     * Lista curada de modelos de imagen (Nano Banana).
     */
    public static function curated_image_models(): array {
        return [
            ['id' => 'gemini-2.5-flash-image',       'label' => 'Nano Banana (Gemini 2.5 Flash Image)'],
            ['id' => 'gemini-3.1-flash-image-preview','label' => 'Nano Banana 2 (Gemini 3.1 Flash Image Preview)'],
            ['id' => 'gemini-3-pro-image-preview',   'label' => 'Nano Banana Pro (Gemini 3 Pro Image Preview)'],
        ];
    }

    /**
     * Valida (opcional) los modelos curados contra el endpoint /models.
     * Retorna: [ 'text' => [...], 'image' => [...], 'fetched_at' => time() ]
     */
    public static function validate_curated(string $api_key): array {
        $client = new AIBlogger_Gemini_Client($api_key);
        $models = $client->list_models();
        if (is_wp_error($models)) {
            return [
                'text' => self::curated_text_models(),
                'image' => self::curated_image_models(),
                'fetched_at' => time(),
                'error' => $models->get_error_message(),
            ];
        }

        $available = [];
        foreach ($models as $m) {
            if (!empty($m['id'])) $available[(string)$m['id']] = true;
        }

        $text = [];
        foreach (self::curated_text_models() as $m) {
            if (!empty($available[$m['id']])) $text[] = $m;
        }
        $image = [];
        foreach (self::curated_image_models() as $m) {
            if (!empty($available[$m['id']])) $image[] = $m;
        }

        // Si la API no devolvió alguno, mantenemos la lista original para no dejar vacío.
        if (!$text) $text = self::curated_text_models();
        if (!$image) $image = self::curated_image_models();

        return [
            'text' => $text,
            'image' => $image,
            'fetched_at' => time(),
        ];
    }
}
