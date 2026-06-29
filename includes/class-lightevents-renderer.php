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
            'source' => 'api',
        ], $atts, 'lightevents_events');

        $events = $atts['source'] === 'wordpress' ? $this->events_from_posts((int)$atts['limit']) : $this->api->events([
            'country' => $atts['country'],
            'city' => $atts['city'],
            'category' => $atts['category'],
            'organizer' => $atts['organizer'],
            'status' => $atts['status'],
            'from' => $atts['from'],
            'to' => $atts['to'],
        ]);

        if (is_wp_error($events)) { return $this->error($events); }
        if (!is_array($events)) { return '<div class="lightevents-empty">' . esc_html__('Aucun événement trouvé.', 'lightevents') . '</div>'; }

        $events = $this->filter_and_limit($events, (int) $atts['limit'], $atts['show_past'] === 'true');
        $view = sanitize_key($atts['view']);
        if ($view === 'calendar') { return $this->calendar($events); }
        if ($view === 'agenda' || $view === 'list') { return $this->event_list($events); }
        if ($view === 'map') { return $this->map_stub($events); }
        return $this->grid($events);
    }

    public function event_shortcode(array $atts = []): string {
        $atts = shortcode_atts(['id' => 0, 'checkout' => 'true'], $atts, 'lightevents_event');
        $id = absint($atts['id']);
        if (!$id) { return '<div class="lightevents-error">' . esc_html__('ID événement manquant.', 'lightevents') . '</div>'; }
        $event = $this->api->event($id);
        if (is_wp_error($event)) { return $this->error($event); }
        if (!is_array($event)) { return '<div class="lightevents-empty">' . esc_html__('Événement introuvable.', 'lightevents') . '</div>'; }
        return $this->event_detail($event, $atts['checkout'] === 'true');
    }

    public function checkout_shortcode(array $atts = []): string {
        $atts = shortcode_atts(['event' => 0], $atts, 'lightevents_checkout');
        $event = $this->api->event(absint($atts['event']));
        if (is_wp_error($event)) { return $this->error($event); }
        return $this->checkout_form($event);
    }

    private function events_from_posts(int $limit): array {
        $q = new WP_Query(['post_type' => 'lightevents_event', 'post_status' => 'publish', 'posts_per_page' => $limit > 0 ? $limit : -1, 'meta_key' => '_lightevents_starts_at', 'orderby' => 'meta_value', 'order' => 'ASC']);
        $events = [];
        foreach ($q->posts as $post) {
            $payload = json_decode((string)get_post_meta($post->ID, '_lightevents_payload', true), true);
            if (is_array($payload)) { $payload['wordpressUrl'] = get_permalink($post); $events[] = $payload; }
        }
        wp_reset_postdata();
        return $events;
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
        $html = '<div class="lightevents-shell"><div class="lightevents-grid">';
        foreach ($events as $event) { $html .= $this->card($event); }
        return $html . '</div></div>';
    }

    private function event_list(array $events): string {
        if (!$events) { return '<div class="lightevents-empty">' . esc_html__('Aucun événement à afficher.', 'lightevents') . '</div>'; }
        $html = '<div class="lightevents-shell"><div class="lightevents-list">';
        foreach ($events as $event) {
            $html .= '<article class="lightevents-list-item">' . $this->date_badge($event) . '<div><h3>' . esc_html($event['title'] ?? '') . '</h3><p>' . esc_html($this->location($event)) . '</p>' . $this->ticket_prices($event) . '<a class="lightevents-link" href="' . esc_url($this->event_url($event)) . '">' . esc_html__('Voir et réserver', 'lightevents') . '</a></div></article>';
        }
        return $html . '</div></div>';
    }

    private function calendar(array $events): string {
        $by_day = [];
        foreach ($events as $event) { $by_day[date_i18n('Y-m-d', strtotime($event['startsAt'] ?? 'now'))][] = $event; }
        ksort($by_day);
        $html = '<div class="lightevents-shell"><div class="lightevents-calendar">';
        foreach ($by_day as $day => $items) {
            $html .= '<section><h3>' . esc_html(date_i18n(get_option('date_format'), strtotime($day))) . '</h3>';
            foreach ($items as $event) { $html .= $this->card($event); }
            $html .= '</section>';
        }
        return $html . '</div></div>';
    }

    private function map_stub(array $events): string {
        $html = '<div class="lightevents-shell"><div class="lightevents-map-list"><p class="lightevents-note">' . esc_html__('Vue carte prête pour intégration Mapbox/Google Maps. Les événements géolocalisés sont listés ci-dessous.', 'lightevents') . '</p>';
        foreach ($events as $event) { $html .= $this->card($event); }
        return $html . '</div></div>';
    }

    private function card(array $event): string {
        $image = $event['coverImageUrl'] ?? $event['generatedImageUrl'] ?? '';
        $html = '<article class="lightevents-card">';
        if ($image) { $html .= '<a class="lightevents-card-media" href="' . esc_url($this->event_url($event)) . '"><img src="' . esc_url($image) . '" alt=""></a>'; }
        $html .= '<div class="lightevents-card-body"><div class="lightevents-card-top">' . $this->date_badge($event) . $this->status_pill($event) . '</div>';
        $html .= '<h3>' . esc_html($event['title'] ?? '') . '</h3>';
        $html .= '<p class="lightevents-meta">' . esc_html($this->location($event)) . '</p>';
        $html .= $this->ticket_prices($event);
        $html .= '<a class="lightevents-button" href="' . esc_url($this->event_url($event)) . '">' . esc_html__('Voir / réserver', 'lightevents') . '</a>';
        $html .= '</div></article>';
        return $html;
    }

    private function event_detail(array $event, bool $show_checkout): string {
        $image = $event['coverImageUrl'] ?? $event['generatedImageUrl'] ?? '';
        $html = '<article class="lightevents-detail">';
        if ($image) { $html .= '<img class="lightevents-hero" src="' . esc_url($image) . '" alt="">'; }
        $html .= '<div class="lightevents-detail-body"><div class="lightevents-detail-main">';
        $html .= $this->date_badge($event) . '<h2>' . esc_html($event['title'] ?? '') . '</h2>';
        $html .= '<p class="lightevents-meta">' . esc_html($this->location($event)) . '</p>';
        $html .= $this->category_badges($event);
        $html .= '<div class="lightevents-description">' . wp_kses_post(wpautop((string)($event['description'] ?? ''))) . '</div></div>';
        $html .= '<aside class="lightevents-detail-aside">' . $this->ticket_prices($event) . '<p class="lightevents-fee-label">' . esc_html(get_option('lightevents_fee_label', 'Frais plateforme LightEvents: 4,5% inclus dans le prix affiché')) . '</p>';
        if ($show_checkout) { $html .= $this->checkout_form($event); }
        $html .= '</aside></div></article>';
        return $html;
    }

    private function checkout_form(array $event): string {
        $event_id = absint($event['id'] ?? 0);
        $tickets = is_array($event['tickets'] ?? null) ? $event['tickets'] : [];
        if (!$event_id || !$tickets) { return '<div class="lightevents-empty">' . esc_html__('Billetterie bientôt disponible pour cet événement.', 'lightevents') . '</div>'; }

        $html = '<form class="lightevents-checkout" data-lightevents-checkout><h3>' . esc_html__('Billetterie sécurisée', 'lightevents') . '</h3><p class="lightevents-note">' . esc_html__('Réservez ou payez maintenant. Après confirmation, chaque participant reçoit un QR ticket scannable avec LightEvents Organizer.', 'lightevents') . '</p>';
        $html .= '<input type="hidden" name="action" value="lightevents_checkout"><input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('lightevents_checkout')) . '"><input type="hidden" name="eventId" value="' . esc_attr((string) $event_id) . '">';
        $html .= '<label>' . esc_html__('Billet', 'lightevents') . '<select name="ticketTypeId" required>';
        foreach ($tickets as $ticket) {
            $price = isset($ticket['price']) ? (float) $ticket['price'] : 0.0;
            $currency = $ticket['currency'] ?? get_option('lightevents_default_currency', 'XOF');
            $remaining = max(0, (int)($ticket['quantity'] ?? 0) - (int)($ticket['sold'] ?? 0));
            $label = ($ticket['name'] ?? __('Billet', 'lightevents')) . ' — ' . ($price <= 0 ? __('Gratuit', 'lightevents') : number_format_i18n($price, 2) . ' ' . $currency) . ' · ' . sprintf(__('%d restant(s)', 'lightevents'), $remaining);
            $html .= '<option value="' . esc_attr((string)($ticket['id'] ?? '')) . '" data-price="' . esc_attr((string) $price) . '" data-currency="' . esc_attr($currency) . '" ' . disabled($remaining <= 0, true, false) . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<div class="lightevents-two"><label>' . esc_html__('Quantité', 'lightevents') . '<input name="quantity" type="number" min="1" value="1" required></label><label>' . esc_html__('Code promotionnel', 'lightevents') . '<input name="promoCode" placeholder="EARLYBIRD"></label></div>';
        $html .= '<label>' . esc_html__('Nom complet', 'lightevents') . '<input name="buyerName" autocomplete="name" required></label>';
        $html .= '<label>' . esc_html__('Email', 'lightevents') . '<input name="buyerEmail" type="email" autocomplete="email" required></label>';
        $html .= '<label>' . esc_html__('Téléphone / WhatsApp', 'lightevents') . '<input name="buyerPhone" inputmode="tel" autocomplete="tel"></label>';
        $html .= '<label>' . esc_html__('Paiement', 'lightevents') . '<select name="provider">' . $this->payment_options() . '</select></label>';
        $html .= '<label class="lightevents-otp">' . esc_html__('OTP Orange Money si demandé', 'lightevents') . '<input name="paymentOtp" inputmode="numeric" maxlength="8" placeholder="123456"></label>';
        $html .= '<div class="lightevents-total" data-lightevents-total></div><div class="lightevents-actions"><button class="lightevents-button secondary" type="submit" name="mode" value="reserve">' . esc_html__('Réserver', 'lightevents') . '</button><button class="lightevents-button" type="submit" name="mode" value="pay">' . esc_html__('Payer maintenant', 'lightevents') . '</button></div><div class="lightevents-form-message" aria-live="polite"></div></form>';
        return $html;
    }

    private function payment_options(): string {
        $labels = ['ORANGE_MONEY'=>'Orange Money','MTN_MONEY'=>'MTN MoMo','WAVE'=>'Wave','AIRTEL_MONEY'=>'Airtel Money','MOOV_MONEY'=>'Moov Money','STRIPE'=>'Carte bancaire','PAYPAL'=>'PayPal'];
        $enabled = array_filter(array_map('trim', explode(',', (string)get_option('lightevents_payment_methods', 'ORANGE_MONEY,MTN_MONEY,WAVE,AIRTEL_MONEY,MOOV_MONEY,STRIPE,PAYPAL'))));
        $html = '';
        foreach ($enabled as $method) { $html .= '<option value="' . esc_attr($method) . '">' . esc_html($labels[$method] ?? $method) . '</option>'; }
        return $html;
    }

    private function status_pill(array $event): string {
        $tickets = is_array($event['tickets'] ?? null) ? $event['tickets'] : [];
        $remaining = 0;
        foreach ($tickets as $ticket) { $remaining += max(0, (int)($ticket['quantity'] ?? 0) - (int)($ticket['sold'] ?? 0)); }
        return '<span class="lightevents-status">' . esc_html($remaining > 0 ? sprintf(__('%d places', 'lightevents'), $remaining) : __('Complet', 'lightevents')) . '</span>';
    }

    private function category_badges(array $event): string {
        $raw = (string)($event['categories'] ?? ($event['category'] ?? ''));
        $categories = array_filter(array_map('trim', explode(',', $raw)));
        if (!$categories) { return ''; }
        $html = '<div class="lightevents-categories">';
        foreach ($categories as $category) { $html .= '<span>' . esc_html($category) . '</span>'; }
        return $html . '</div>';
    }

    private function ticket_prices(array $event): string {
        $tickets = is_array($event['tickets'] ?? null) ? $event['tickets'] : [];
        if (!$tickets) { return '<p class="lightevents-prices">' . esc_html__('Prix à définir', 'lightevents') . '</p>'; }
        $html = '<div class="lightevents-prices">';
        foreach ($tickets as $ticket) {
            $price = isset($ticket['price']) ? (float) $ticket['price'] : 0.0;
            $currency = $ticket['currency'] ?? get_option('lightevents_default_currency', 'XOF');
            $label = ($ticket['name'] ?? __('Billet', 'lightevents')) . ' · ' . ($price <= 0 ? __('Gratuit', 'lightevents') : number_format_i18n($price, 2) . ' ' . $currency);
            $html .= '<span>' . esc_html($label) . '</span>';
        }
        return $html . '</div>';
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
        if (!empty($event['wordpressUrl'])) { return (string)$event['wordpressUrl']; }
        $post = get_posts(['post_type' => 'lightevents_event', 'meta_key' => '_lightevents_event_id', 'meta_value' => (string)($event['id'] ?? ''), 'fields' => 'ids', 'posts_per_page' => 1]);
        if ($post) { return get_permalink((int)$post[0]); }
        $page_id = absint(get_option('lightevents_event_page_id', 0));
        if ($page_id) { return add_query_arg('lightevents_event', absint($event['id'] ?? 0), get_permalink($page_id)); }
        return $this->api->platform_url() . '/events/' . rawurlencode((string)($event['id'] ?? ''));
    }

    private function error(WP_Error $error): string {
        $details = $error->get_error_data();
        $suffix = is_array($details) && !empty($details['status']) ? ' (' . (int)$details['status'] . ')' : '';
        return '<div class="lightevents-error">' . esc_html($error->get_error_message() . $suffix) . '</div>';
    }
}
