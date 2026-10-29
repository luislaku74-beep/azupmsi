<?php

function renderToastMessages(array $messages): void
{
    $messages = array_values(array_filter(
        $messages,
        static function ($item): bool {
            return is_array($item)
                && isset($item['message'])
                && trim((string)$item['message']) !== '';
        }
    ));

    if ($messages === []) {
        return;
    }

    echo '<div class="toast-container" aria-label="Notifications">';

    foreach ($messages as $item) {
        $type = in_array($item['type'] ?? '', ['success', 'error', 'info'], true)
            ? $item['type']
            : 'info';
        $role = $type === 'error' ? 'alert' : 'status';
        $live = $type === 'error' ? 'assertive' : 'polite';
        $message = htmlspecialchars(
            (string)$item['message'],
            ENT_QUOTES,
            'UTF-8'
        );

        echo '<div class="toast ' . $type . '" role="' . $role
            . '" aria-live="' . $live . '">'
            . '<span class="toast-text">' . $message . '</span>'
            . '<button class="toast-close" type="button"'
            . ' aria-label="Dismiss notification">&times;</button>'
            . '</div>';
    }

    echo '</div>';
}
