<?php
if (!defined('ABSPATH')) { exit; }

class LightEvents_WP_API {
    public function api_base(): string {
        $base = trim((string) get_option('lightevents_api_base', 'https://lighteventstest.onrender.com/api'));
        return rtrim($base ?: 'https://lighteventstest.onrender.com/api', '/');
    }

    public function platform_url(): string {
        $url = trim((string) get_option('lightevents_platform_url', 'https://valere-merval.github.io/lightEventsFE'));
        return rtrim($url ?: 'https://valere-merval.github.io/lightEventsFE', '/');
    }

    public function request(string $method, string $path, array $query = [], $body = null) {
        $url = $this->api_base() . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url = add_query_arg(array_filter($query, static fn($v) => $v !== '' && $v !== null), $url);
        }

        $headers = ['Accept' => 'application/json', 'X-LightEvents-Source' => 'wordpress'];
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
            'timeout' => 20,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? __('Erreur API LightEvents.', 'lightevents')) : __('Erreur API LightEvents.', 'lightevents');
            return new WP_Error('lightevents_api_error', $message, [
                'status' => $code,
                'body' => $data ?: $raw,
                'url' => $url,
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
            'upcomingOnly' => $filters['upcomingOnly'] ?? 'true',
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

    public function register_account(array $payload) {
        return $this->request('POST', '/auth/register', [], $payload);
    }

    public function verify_account(string $email, string $code) {
        return $this->request('POST', '/auth/verify', [], [
            'channel' => 'email',
            'destination' => $email,
            'code' => $code,
        ]);
    }

    public function request_login_code(string $email) {
        return $this->request('POST', '/auth/login/request-code', [], ['email' => $email]);
    }

    public function verify_login(string $email, string $code) {
        return $this->request('POST', '/auth/login/verify', [], ['email' => $email, 'code' => $code]);
    }
}
