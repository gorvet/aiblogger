<?php
defined('ABSPATH') || exit;

abstract class AIBlogger_Ajax_Controller {

    protected static function check_nonce(): void {
        $ok = check_ajax_referer(AIBLOGGER_NONCE_ACTION, 'nonce', false);
        if (!$ok) {
            wp_send_json_error([
                'message' => 'Sesion expirada o nonce invalido. Recarga la pagina y prueba de nuevo.',
            ], 403);
        }
    }

    protected static function log_exception(Throwable $e, string $context): void {
        error_log(sprintf(
            '[AIBlogger] %s: %s in %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    protected static function fail_with_exception(Throwable $e, string $context): void {
        self::log_exception($e, $context);
        wp_send_json_error([
            'message' => 'Error interno al procesar la solicitud.',
            'details' => [
                'context' => $context,
                'exception' => $e->getMessage(),
            ],
        ], 500);
    }

    protected static function budget_message(array $budget): string {
        $parts = [];
        if (!empty($budget['daily_limit'])) {
            $parts[] = 'diario ' . (int)$budget['used_daily'] . '/' . (int)$budget['daily_limit'];
        }
        if (!empty($budget['monthly_limit'])) {
            $parts[] = 'mensual ' . (int)$budget['used_monthly'] . '/' . (int)$budget['monthly_limit'];
        }
        return $parts ? ('Limite de tokens alcanzado (' . implode(' | ', $parts) . ').') : 'Limite de tokens alcanzado.';
    }

    protected static function enforce_budget_or_fail(): array {
        $budget = AIBlogger_Utils::site_token_budget_status();
        if (!empty($budget['exceeded']) && ($budget['mode'] ?? 'warn') === 'block') {
            wp_send_json_error([
                'message' => self::budget_message($budget),
                'budget' => $budget,
            ], 429);
        }
        return $budget;
    }
}
