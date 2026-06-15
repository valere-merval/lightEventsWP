<?php
if (!defined('ABSPATH')) { exit; }

class LightEvents_WP_API {
    public function api_base(): string {
        $base = trim((string) get_option('lightevents_api_base', 'http://localhost:8080/api'));
        return rtrim($base ?: 'http://localhost:8080/api', '/');
    }

    public function platform_url(): string {
        $url = trim((string) get_option('lightevents_platform_url', 'http://localhost:5173'));
        return rtrim($url ?: 'http://localhost:5173', '/');
    }

    public function request(string $method, string $path, array $query = [], $body = null) {
        $url = $this->api_base() . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url = add_query_arg(array_filter($query, static fn($v) => $v !== '' && $v !== null), $url);
        }

        $headers = ['Accept' => 'application/json'];
        $token = trim((string) get_option('lightevents_api_token', ''));
        if ($token !== '') {
            $headers['X-LightEvents-Token'] = $token;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request($url, [
            'method' => strtoupper($method),
            'headers' => $headers,
            'body' => $body === null ? null : wp_json_encode($body),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            return new WP_Error('lightevents_api_error', __('Erreur API LightEvents.', 'lightevents'), [
                'status' => $code,
                'body' => $data ?: $raw,
            ]);
        }

        return $data;
    }

    public function events(array $filters = []) {
        return $this->request('GET', '/events', [
            'publishedOnly' => $filters['publishedOnly'] ?? 'true',
            'country' => $filters['country'] ?? null,
            'city' => $filters['city'] ?? null,
            'category' => $filters['category'] ?? null,
            'organizer' => $filters['organizer'] ?? null,
            'status' => $filters['status'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ]);
    }

    public function event($id) {
        return $this->request('GET', '/events/' . rawurlencode((string) $id));
    }

    public function reserve($event_id, array $payload) {
        return $this->request('POST', '/events/' . rawurlencode((string) $event_id) . '/reservations', [], $payload);
    }

    public function checkout(array $payload) {
        return $this->request('POST', '/payments/checkout', [], $payload);
    }
}
