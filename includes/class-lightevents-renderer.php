<?php
if (!defined('ABSPATH')) { exit; }

class LightEvents_WP_Renderer {
    private LightEvents_WP_API $api;

    public function __construct(LightEvents_WP_API $api) {
        $this->api = $api;
    }

    public function events_shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'view' => 'grid',
            'country' => '',
            'city' => '',
            'category' => '',
            'organizer' => '',
            'status' => '',
            'from' => '',
            'to' => '',
            'limit' => 12,
            'show_past' => 'false',
        ], $atts, 'lightevents_events');

        $events = $this->api->events([
            'country' => $atts['country'],
            'city' => $atts['city'],
            'category' => $atts['category'],
            'organizer' => $atts['organizer'],
            'status' => $atts['status'],
            'from' => $atts['from'],
            'to' => $atts['to'],
        ]);

        if (is_wp_error($events)) {
            return $this->error($events);
        }

        if (!is_array($events)) {
            return '<div class="lightevents-empty">' . esc_html__('Aucun événement trouvé.', 'lightevents') . '</div>';
        }

        $events = $this->filter_and_limit($events, (int) $atts['limit'], $atts['show_past'] === 'true');
        $view = sanitize_key($atts['view']);

        if ($view === 'calendar') {
            return $this->calendar($events);
        }
        if ($view === 'agenda' || $view === 'list') {
            return $this->event_list($events);
        }
        if ($view === 'map') {
            return $this->map_stub($events);
        }
        return $this->grid($events);
    }

    public function event_shortcode(array $atts = []): string {
        $atts = shortcode_atts(['id' => 0, 'checkout' => 'true'], $atts, 'lightevents_event');
        $id = absint($atts['id']);
        if (!$id) {
            return '<div class="lightevents-error">' . esc_html__('ID événement manquant.', 'lightevents') . '</div>';
        }

        $event = $this->api->event($id);
        if (is_wp_error($event)) {
            return $this->error($event);
        }
        if (!is_array($event)) {
            return '<div class="lightevents-empty">' . esc_html__('Événement introuvable.', 'lightevents') . '</div>';
        }

        return $this->event_detail($event, $atts['checkout'] === 'true');
    }

    public function checkout_shortcode(array $atts = []): string {
        $atts = shortcode_atts(['event' => 0], $atts, 'lightevents_checkout');
        $event = $this->api->event(absint($atts['event']));
        if (is_wp_error($event)) {
            return $this->error($event);
        }
        return $this->checkout_form($event);
    }

    private function filter_and_limit(array $events, int $limit, bool $show_past): array {
        $now = current_time('timestamp');
        $filtered = array_values(array_filter($events, static function ($event) use ($show_past, $now) {
            if ($show_past) { return true; }
            $ts = strtotime($event['startsAt'] ?? '');
            return !$ts || $ts >= $now - DAY_IN_SECONDS;
        }));
        usort($filtered, static fn($a, $b) => strcmp((string)($a['startsAt'] ?? ''), (string)($b['startsAt'] ?? '')));
        return $limit > 0 ? array_slice($filtered, 0, $limit) : $filtered;
    }

    private function grid(array $events): string {
        if (!$events) { return '<div class="lightevents-empty">' . esc_html__('Aucun événement à afficher.', 'lightevents') . '</div>'; }
        $html = '<div class="lightevents-grid">';
        foreach ($events as $event) { $html .= $this->card($event); }
        return $html . '</div>';
    }

    private function event_list(array $events): string {
        if (!$events) { return '<div class="lightevents-empty">' . esc_html__('Aucun événement à afficher.', 'lightevents') . '</div>'; }
        $html = '<div class="lightevents-list">';
        foreach ($events as $event) {
            $html .= '<article class="lightevents-list-item">' . $this->date_badge($event) . '<div><h3>' . esc_html($event['title'] ?? '') . '</h3><p>' . esc_html($this->location($event)) . '</p><a class="lightevents-link" href="' . esc_url($this->event_url($event)) . '">' . esc_html__('Voir et réserver', 'lightevents') . '</a></div></article>';
        }
        return $html . '</div>';
    }

    private function calendar(array $events): string {
        $by_day = [];
        foreach ($events as $event) {
            $day = date_i18n('Y-m-d', strtotime($event['startsAt'] ?? 'now'));
            $by_day[$day][] = $event;
        }
        ksort($by_day);
        $html = '<div class="lightevents-calendar">';
        foreach ($by_day as $day => $items) {
            $html .= '<section><h3>' . esc_html(date_i18n(get_option('date_format'), strtotime($day))) . '</h3>';
            foreach ($items as $event) { $html .= $this->card($event); }
            $html .= '</section>';
        }
        return $html . '</div>';
    }

    private function map_stub(array $events): string {
        $html = '<div class="lightevents-map-list"><p class="lightevents-note">' . esc_html__('Vue carte prête pour intégration Mapbox/Google Maps. Les événements géolocalisés sont listés ci-dessous.', 'lightevents') . '</p>';
        foreach ($events as $event) { $html .= $this->card($event); }
        return $html . '</div>';
    }

    private function card(array $event): string {
        $image = $event['coverImageUrl'] ?? $event['generatedImageUrl'] ?? '';
        $html = '<article class="lightevents-card">';
        if ($image) { $html .= '<a href="' . esc_url($this->event_url($event)) . '"><img src="' . esc_url($image) . '" alt=""></a>'; }
        $html .= '<div class="lightevents-card-body">' . $this->date_badge($event);
        $html .= '<h3>' . esc_html($event['title'] ?? '') . '</h3>';
        $html .= '<p>' . esc_html($this->location($event)) . '</p>';
        $html .= '<a class="lightevents-button" href="' . esc_url($this->event_url($event)) . '">' . esc_html__('Voir / réserver', 'lightevents') . '</a>';
        $html .= '</div></article>';
        return $html;
    }

    private function event_detail(array $event, bool $show_checkout): string {
        $image = $event['coverImageUrl'] ?? $event['generatedImageUrl'] ?? '';
        $html = '<article class="lightevents-detail">';
        if ($image) { $html .= '<img class="lightevents-hero" src="' . esc_url($image) . '" alt="">'; }
        $html .= '<div class="lightevents-detail-body">';
        $html .= $this->date_badge($event) . '<h2>' . esc_html($event['title'] ?? '') . '</h2>';
        $html .= '<p class="lightevents-meta">' . esc_html($this->location($event)) . '</p>';
        $html .= '<div class="lightevents-description">' . wp_kses_post(wpautop((string)($event['description'] ?? ''))) . '</div>';
        if ($show_checkout) { $html .= $this->checkout_form($event); }
        $html .= '</div></article>';
        return $html;
    }

    private function checkout_form(array $event): string {
        $event_id = absint($event['id'] ?? 0);
        $tickets = is_array($event['tickets'] ?? null) ? $event['tickets'] : [];
        if (!$event_id || !$tickets) {
            return '<div class="lightevents-empty">' . esc_html__('Billetterie bientôt disponible pour cet événement.', 'lightevents') . '</div>';
        }

        $html = '<form class="lightevents-checkout" data-lightevents-checkout><h3>' . esc_html__('Réserver ou payer', 'lightevents') . '</h3>';
        $html .= '<input type="hidden" name="action" value="lightevents_checkout"><input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('lightevents_checkout')) . '"><input type="hidden" name="eventId" value="' . esc_attr((string) $event_id) . '">';
        $html .= '<label>' . esc_html__('Billet', 'lightevents') . '<select name="ticketTypeId" required>';
        foreach ($tickets as $ticket) {
            $price = isset($ticket['price']) ? (float) $ticket['price'] : 0.0;
            $currency = $ticket['currency'] ?? 'EUR';
            $label = ($ticket['name'] ?? __('Billet', 'lightevents')) . ' — ' . number_format_i18n($price, 2) . ' ' . $currency;
            $html .= '<option value="' . esc_attr((string)($ticket['id'] ?? '')) . '" data-price="' . esc_attr((string) $price) . '" data-currency="' . esc_attr($currency) . '">' . esc_html($label) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<label>' . esc_html__('Quantité', 'lightevents') . '<input name="quantity" type="number" min="1" value="1" required></label>';
        $html .= '<label>' . esc_html__('Nom complet', 'lightevents') . '<input name="buyerName" required></label>';
        $html .= '<label>' . esc_html__('Email', 'lightevents') . '<input name="buyerEmail" type="email" required></label>';
        $html .= '<label>' . esc_html__('Téléphone / WhatsApp', 'lightevents') . '<input name="buyerPhone" inputmode="tel"></label>';
        $html .= '<label>' . esc_html__('Paiement', 'lightevents') . '<select name="provider"><option value="ORANGE_MONEY">Orange Money</option><option value="MTN_MONEY">MTN MoMo</option><option value="WAVE">Wave</option><option value="AIRTEL_MONEY">Airtel Money</option><option value="MOOV_MONEY">Moov Money</option><option value="STRIPE">Carte bancaire</option><option value="PAYPAL">PayPal</option></select></label>';
        $html .= '<div class="lightevents-actions"><button class="lightevents-button secondary" type="submit" name="mode" value="reserve">' . esc_html__('Réserver', 'lightevents') . '</button><button class="lightevents-button" type="submit" name="mode" value="pay">' . esc_html__('Payer maintenant', 'lightevents') . '</button></div><div class="lightevents-form-message" aria-live="polite"></div></form>';
        return $html;
    }

    private function date_badge(array $event): string {
        $ts = strtotime($event['startsAt'] ?? '');
        if (!$ts) { return ''; }
        return '<span class="lightevents-date"><strong>' . esc_html(date_i18n('d M', $ts)) . '</strong><small>' . esc_html(date_i18n('H:i', $ts)) . '</small></span>';
    }

    private function location(array $event): string {
        if (!empty($event['online'])) { return __('En ligne', 'lightevents'); }
        return trim(implode(', ', array_filter([$event['venueName'] ?? '', $event['city'] ?? '', $event['country'] ?? ''])));
    }

    private function event_url(array $event): string {
        $page_id = absint(get_option('lightevents_event_page_id', 0));
        if ($page_id) {
            return add_query_arg('lightevents_event', absint($event['id'] ?? 0), get_permalink($page_id));
        }
        return $this->api->platform_url() . '/events/' . rawurlencode((string)($event['id'] ?? ''));
    }

    private function error(WP_Error $error): string {
        return '<div class="lightevents-error">' . esc_html($error->get_error_message()) . '</div>';
    }
}
