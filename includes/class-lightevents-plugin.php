<?php
if (!defined('ABSPATH')) { exit; }

class LightEvents_WP_Plugin {
    private static ?self $instance = null;
    private LightEvents_WP_API $api;
    private LightEvents_WP_Renderer $renderer;

    public static function instance(): self {
        if (!self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        $this->api = new LightEvents_WP_API();
        $this->renderer = new LightEvents_WP_Renderer($this->api);
    }

    public function boot(): void {
        add_action('init', [$this, 'register_shortcodes_and_blocks']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('wp_ajax_lightevents_checkout', [$this, 'ajax_checkout']);
        add_action('wp_ajax_nopriv_lightevents_checkout', [$this, 'ajax_checkout']);
        add_filter('the_content', [$this, 'render_event_page_placeholder']);
    }

    public function register_shortcodes_and_blocks(): void {
        add_shortcode('lightevents_events', [$this->renderer, 'events_shortcode']);
        add_shortcode('lightevents_event', [$this->renderer, 'event_shortcode']);
        add_shortcode('lightevents_checkout', [$this->renderer, 'checkout_shortcode']);

        register_block_type(LIGHTEVENTS_WP_DIR . 'blocks/events', [
            'render_callback' => fn($attrs) => $this->renderer->events_shortcode(is_array($attrs) ? $attrs : []),
        ]);
        register_block_type(LIGHTEVENTS_WP_DIR . 'blocks/event-detail', [
            'render_callback' => fn($attrs) => $this->renderer->event_shortcode(is_array($attrs) ? $attrs : []),
        ]);
    }

    public function assets(): void {
        wp_enqueue_style('lightevents-wp', LIGHTEVENTS_WP_URL . 'assets/lightevents.css', [], LIGHTEVENTS_WP_VERSION);
        wp_enqueue_script('lightevents-wp', LIGHTEVENTS_WP_URL . 'assets/lightevents.js', [], LIGHTEVENTS_WP_VERSION, true);
        wp_localize_script('lightevents-wp', 'LightEventsWP', ['ajaxUrl' => admin_url('admin-ajax.php')]);
    }

    public function admin_menu(): void {
        add_options_page('LightEvents', 'LightEvents', 'manage_options', 'lightevents', [$this, 'settings_page']);
    }

    public function settings(): void {
        register_setting('lightevents', 'lightevents_api_base', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('lightevents', 'lightevents_platform_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('lightevents', 'lightevents_api_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('lightevents', 'lightevents_event_page_id', ['sanitize_callback' => 'absint']);
    }

    public function settings_page(): void {
        ?>
        <div class="wrap">
            <h1>LightEvents</h1>
            <p>Connecte WordPress à LightEvents pour afficher les événements, prendre des réservations et lancer les paiements Mobile Money/carte/PayPal.</p>
            <form method="post" action="options.php">
                <?php settings_fields('lightevents'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="lightevents_api_base">API LightEvents</label></th><td><input class="regular-text" id="lightevents_api_base" name="lightevents_api_base" value="<?php echo esc_attr(get_option('lightevents_api_base', 'http://localhost:8080/api')); ?>" placeholder="https://api.example.com/api"></td></tr>
                    <tr><th scope="row"><label for="lightevents_platform_url">URL plateforme</label></th><td><input class="regular-text" id="lightevents_platform_url" name="lightevents_platform_url" value="<?php echo esc_attr(get_option('lightevents_platform_url', 'http://localhost:5173')); ?>" placeholder="https://app.example.com"></td></tr>
                    <tr><th scope="row"><label for="lightevents_api_token">Token API</label></th><td><input class="regular-text" id="lightevents_api_token" name="lightevents_api_token" value="<?php echo esc_attr(get_option('lightevents_api_token', '')); ?>" autocomplete="off"><p class="description">Optionnel pour la lecture publique, requis plus tard pour création/sync avancée.</p></td></tr>
                    <tr><th scope="row"><label for="lightevents_event_page_id">Page détail WordPress</label></th><td><?php wp_dropdown_pages(['name' => 'lightevents_event_page_id', 'selected' => absint(get_option('lightevents_event_page_id', 0)), 'show_option_none' => '— Rediriger vers LightEvents —']); ?><p class="description">Si une page contient le shortcode [lightevents_event_from_query], elle peut servir de page détail.</p></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Shortcodes</h2>
            <ul>
                <li><code>[lightevents_events]</code></li>
                <li><code>[lightevents_events view="calendar" country="Côte d'Ivoire" category="business"]</code></li>
                <li><code>[lightevents_event id="123"]</code></li>
                <li><code>[lightevents_checkout event="123"]</code></li>
            </ul>
        </div>
        <?php
    }

    public function render_event_page_placeholder(string $content): string {
        if (is_admin() || !is_singular() || empty($_GET['lightevents_event'])) { return $content; }
        if (strpos($content, '[lightevents_event_from_query]') === false) { return $content; }
        return str_replace('[lightevents_event_from_query]', $this->renderer->event_shortcode(['id' => absint($_GET['lightevents_event'])]), $content);
    }

    public function ajax_checkout(): void {
        check_ajax_referer('lightevents_checkout', 'nonce');

        $event_id = absint($_POST['eventId'] ?? 0);
        $ticket_id = absint($_POST['ticketTypeId'] ?? 0);
        $quantity = max(1, absint($_POST['quantity'] ?? 1));
        $mode = sanitize_key($_POST['mode'] ?? 'reserve');
        $buyer_name = sanitize_text_field(wp_unslash($_POST['buyerName'] ?? ''));
        $buyer_email = sanitize_email(wp_unslash($_POST['buyerEmail'] ?? ''));
        $buyer_phone = sanitize_text_field(wp_unslash($_POST['buyerPhone'] ?? ''));
        $provider = sanitize_text_field(wp_unslash($_POST['provider'] ?? 'ORANGE_MONEY'));

        if (!$event_id || !$ticket_id || !$buyer_name || !$buyer_email) {
            wp_send_json_error(['message' => __('Merci de remplir les champs obligatoires.', 'lightevents')], 400);
        }

        $reservation = $this->api->reserve($event_id, [
            'buyerName' => $buyer_name,
            'buyerEmail' => $buyer_email,
            'buyerPhone' => $buyer_phone,
            'buyerWhatsapp' => $buyer_phone,
            'companyPurchase' => false,
            'deliveryPreference' => 'email',
            'ticketTypeId' => $ticket_id,
            'quantity' => $quantity,
            'holders' => [],
            'payNow' => $mode === 'pay',
        ]);

        if (is_wp_error($reservation)) {
            wp_send_json_error(['message' => $reservation->get_error_message(), 'details' => $reservation->get_error_data()], 502);
        }

        if ($mode !== 'pay') {
            wp_send_json_success(['message' => __('Réservation enregistrée. Les tickets QR sont envoyés par email et seront scannables avec l’app LightEvents Organizer.', 'lightevents'), 'reservation' => $reservation]);
        }

        $event = $this->api->event($event_id);
        $amount = $this->ticket_amount(is_wp_error($event) ? [] : $event, $ticket_id, $quantity);
        $currency = $this->ticket_currency(is_wp_error($event) ? [] : $event, $ticket_id);
        if ($amount <= 0) {
            wp_send_json_success(['message' => __('Billet gratuit confirmé. Le ticket QR est envoyé par email.', 'lightevents'), 'reservation' => $reservation]);
        }
        $reference = is_array($reservation) ? ($reservation['reservation']['reference'] ?? $reservation['reference'] ?? null) : null;
        $checkout = $this->api->checkout([
            'eventId' => $event_id,
            'reservationReference' => $reference,
            'provider' => $provider,
            'amount' => $amount,
            'currency' => $currency,
            'payerPhone' => $buyer_phone,
        ]);

        if (is_wp_error($checkout)) {
            wp_send_json_error(['message' => $checkout->get_error_message(), 'reservation' => $reservation], 502);
        }

        wp_send_json_success(['message' => __('Paiement initialisé. Après confirmation, les tickets QR seront envoyés par email.', 'lightevents'), 'reservation' => $reservation, 'checkout' => $checkout]);
    }

    private function ticket_amount(array $event, int $ticket_id, int $quantity): float {
        foreach (($event['tickets'] ?? []) as $ticket) {
            if ((int)($ticket['id'] ?? 0) === $ticket_id) {
                return round(((float)($ticket['price'] ?? 0)) * $quantity, 2);
            }
        }
        return 0.0;
    }

    private function ticket_currency(array $event, int $ticket_id): string {
        foreach (($event['tickets'] ?? []) as $ticket) {
            if ((int)($ticket['id'] ?? 0) === $ticket_id) {
                return (string)($ticket['currency'] ?? 'EUR');
            }
        }
        return 'EUR';
    }
}
