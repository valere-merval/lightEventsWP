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

    public static function activate(): void {
        add_option('lightevents_activation_redirect', '1');
        add_option('lightevents_default_currency', 'XOF');
        add_option('lightevents_fee_label', 'Frais plateforme LightEvents: 4,5% inclus dans le prix affiché');
        add_option('lightevents_payment_methods', 'ORANGE_MONEY,MTN_MONEY,WAVE,AIRTEL_MONEY,MOOV_MONEY,STRIPE,PAYPAL');
        add_option('lightevents_import_status', 'publish');
        add_option('lightevents_auto_sync', 'daily');
        if (!wp_next_scheduled('lightevents_sync_cron')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'lightevents_sync_cron');
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('lightevents_sync_cron');
    }

    public function boot(): void {
        add_action('init', [$this, 'register_content_types']);
        add_action('init', [$this, 'register_shortcodes_and_blocks']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_init', [$this, 'maybe_activation_redirect']);
        add_action('admin_post_lightevents_import_event', [$this, 'handle_import_event']);
        add_action('admin_post_lightevents_sync_events', [$this, 'handle_sync_events']);
        add_action('admin_post_lightevents_register_account', [$this, 'handle_register_account']);
        add_action('admin_post_lightevents_request_login_code', [$this, 'handle_request_login_code']);
        add_action('admin_post_lightevents_verify_account', [$this, 'handle_verify_account']);
        add_action('admin_post_lightevents_logout_account', [$this, 'handle_logout_account']);
        add_action('current_screen', [$this, 'guard_event_editor']);
        add_action('lightevents_sync_cron', [$this, 'sync_events']);
        add_action('wp_ajax_lightevents_checkout', [$this, 'ajax_checkout']);
        add_action('wp_ajax_nopriv_lightevents_checkout', [$this, 'ajax_checkout']);
        add_filter('the_content', [$this, 'render_event_page_placeholder']);
        add_filter('manage_lightevents_event_posts_columns', [$this, 'event_columns']);
        add_action('manage_lightevents_event_posts_custom_column', [$this, 'event_column_content'], 10, 2);
    }

    public function register_content_types(): void {
        register_post_type('lightevents_event', [
            'labels' => [
                'name' => __('LightEvents Events', 'lightevents'),
                'singular_name' => __('LightEvents Event', 'lightevents'),
                'add_new_item' => __('Ajouter un événement LightEvents', 'lightevents'),
                'edit_item' => __('Modifier l’événement LightEvents', 'lightevents'),
                'view_item' => __('Voir l’événement', 'lightevents'),
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'],
            'has_archive' => true,
            'rewrite' => ['slug' => 'events'],
        ]);

        register_taxonomy('lightevents_category', 'lightevents_event', [
            'label' => __('Event Categories', 'lightevents'),
            'public' => true,
            'show_ui' => true,
            'show_in_rest' => true,
            'hierarchical' => true,
            'rewrite' => ['slug' => 'event-category'],
        ]);

        register_taxonomy('lightevents_tag', 'lightevents_event', [
            'label' => __('Event Tags', 'lightevents'),
            'public' => true,
            'show_ui' => true,
            'show_in_rest' => true,
            'hierarchical' => false,
            'rewrite' => ['slug' => 'event-tag'],
        ]);
    }

    public function register_shortcodes_and_blocks(): void {
        add_shortcode('lightevents_events', [$this->renderer, 'events_shortcode']);
        add_shortcode('lightevents_event', [$this->renderer, 'event_shortcode']);
        add_shortcode('lightevents_checkout', [$this->renderer, 'checkout_shortcode']);
        add_shortcode('lightevents_event_from_query', fn() => $this->renderer->event_shortcode(['id' => absint($_GET['lightevents_event'] ?? 0)]));

        foreach (['events', 'event-detail'] as $block) {
            $path = LIGHTEVENTS_WP_DIR . 'blocks/' . $block;
            if (file_exists($path . '/block.json')) {
                register_block_type($path, [
                    'render_callback' => $block === 'events'
                        ? fn($attrs) => $this->renderer->events_shortcode(is_array($attrs) ? $attrs : [])
                        : fn($attrs) => $this->renderer->event_shortcode(is_array($attrs) ? $attrs : []),
                ]);
            }
        }
    }

    public function assets(): void {
        wp_enqueue_style('lightevents-wp', LIGHTEVENTS_WP_URL . 'assets/lightevents.css', [], LIGHTEVENTS_WP_VERSION);
        wp_enqueue_script('lightevents-wp', LIGHTEVENTS_WP_URL . 'assets/lightevents.js', [], LIGHTEVENTS_WP_VERSION, true);
        wp_localize_script('lightevents-wp', 'LightEventsWP', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'currency' => get_option('lightevents_default_currency', 'XOF'),
        ]);
    }

    public function admin_assets(string $hook): void {
        if (strpos($hook, 'lightevents') === false && get_current_screen()?->post_type !== 'lightevents_event') { return; }
        wp_enqueue_style('lightevents-admin', LIGHTEVENTS_WP_URL . 'assets/lightevents.css', [], LIGHTEVENTS_WP_VERSION);
    }

    public function admin_menu(): void {
        add_menu_page('LightEvents', 'LightEvents', 'manage_options', 'lightevents-dashboard', [$this, 'dashboard_page'], 'dashicons-calendar-alt', 26);
        add_submenu_page('lightevents-dashboard', __('Dashboard', 'lightevents'), __('Dashboard', 'lightevents'), 'manage_options', 'lightevents-dashboard', [$this, 'dashboard_page']);
        add_submenu_page('lightevents-dashboard', __('Compte LightEvents', 'lightevents'), __('Compte / Connexion', 'lightevents'), 'manage_options', 'lightevents-account', [$this, 'account_page']);
        add_submenu_page('lightevents-dashboard', __('Events', 'lightevents'), __('LightEvents Events', 'lightevents'), 'edit_posts', 'edit.php?post_type=lightevents_event');
        add_submenu_page('lightevents-dashboard', __('Add New Event', 'lightevents'), __('Add New Event', 'lightevents'), 'edit_posts', 'post-new.php?post_type=lightevents_event');
        add_submenu_page('lightevents-dashboard', __('Event Categories', 'lightevents'), __('Event Categories', 'lightevents'), 'manage_categories', 'edit-tags.php?taxonomy=lightevents_category&post_type=lightevents_event');
        add_submenu_page('lightevents-dashboard', __('Event Tags', 'lightevents'), __('Event Tags', 'lightevents'), 'manage_categories', 'edit-tags.php?taxonomy=lightevents_tag&post_type=lightevents_event');
        add_submenu_page('lightevents-dashboard', __('Import', 'lightevents'), __('Import / Sync', 'lightevents'), 'manage_options', 'lightevents-import', [$this, 'import_page']);
        add_submenu_page('lightevents-dashboard', __('Settings', 'lightevents'), __('Settings', 'lightevents'), 'manage_options', 'lightevents-settings', [$this, 'settings_page']);
        add_submenu_page('lightevents-dashboard', __('Shortcodes', 'lightevents'), __('Shortcodes', 'lightevents'), 'manage_options', 'lightevents-shortcodes', [$this, 'shortcodes_page']);
        add_submenu_page('lightevents-dashboard', __('Wizard', 'lightevents'), __('Wizard', 'lightevents'), 'manage_options', 'lightevents-wizard', [$this, 'wizard_page']);
        add_submenu_page('lightevents-dashboard', __('Support', 'lightevents'), __('Support & Help', 'lightevents'), 'manage_options', 'lightevents-support', [$this, 'support_page']);
    }

    public function settings(): void {
        register_setting('lightevents', 'lightevents_api_base', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('lightevents', 'lightevents_platform_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('lightevents', 'lightevents_api_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('lightevents', 'lightevents_event_page_id', ['sanitize_callback' => 'absint']);
        register_setting('lightevents', 'lightevents_default_currency', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('lightevents', 'lightevents_payment_methods', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('lightevents', 'lightevents_fee_label', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('lightevents', 'lightevents_import_status', ['sanitize_callback' => 'sanitize_key']);
        register_setting('lightevents', 'lightevents_auto_sync', ['sanitize_callback' => 'sanitize_key']);
    }

    public function maybe_activation_redirect(): void {
        if (!get_option('lightevents_activation_redirect')) { return; }
        delete_option('lightevents_activation_redirect');
        if (wp_doing_ajax() || is_network_admin() || isset($_GET['activate-multi'])) { return; }
        wp_safe_redirect(admin_url('admin.php?page=lightevents-wizard&welcome=1'));
        exit;
    }

    public function dashboard_page(): void {
        $events = $this->api->events(['publishedOnly' => 'true']);
        $imported = wp_count_posts('lightevents_event');
        $event_count = is_array($events) ? count($events) : 0;
        $published = isset($imported->publish) ? (int) $imported->publish : 0;
        ?>
        <div class="wrap lightevents-admin-wrap">
            <?php $this->admin_hero(__('LightEvents Dashboard', 'lightevents'), __('Votre hub WordPress pour vendre, synchroniser et promouvoir vos événements comme Eventbrite — avec Mobile Money, QR tickets et business model LightEvents.', 'lightevents')); ?>
            <div class="lightevents-admin-grid metrics">
                <?php echo $this->metric(__('Événements API', 'lightevents'), (string) $event_count, __('Depuis LightEvents', 'lightevents')); ?>
                <?php echo $this->metric(__('Importés WordPress', 'lightevents'), (string) $published, __('CPT local + SEO', 'lightevents')); ?>
                <?php echo $this->metric(__('Monétisation', 'lightevents'), '4,5%', __('Frais plateforme inclus', 'lightevents')); ?>
                <?php echo $this->metric(__('Paiements', 'lightevents'), '7', __('Orange, MTN, Wave, Airtel, Moov, Carte, PayPal', 'lightevents')); ?>
            </div>
            <div class="lightevents-admin-grid">
                <div class="lightevents-admin-card"><h2><?php esc_html_e('Prochaines actions', 'lightevents'); ?></h2><ol><li><?php esc_html_e('Créer un compte ou se connecter à LightEvents.', 'lightevents'); ?></li><li><?php esc_html_e('Importer un événement ou synchroniser tout le catalogue.', 'lightevents'); ?></li><li><?php esc_html_e('Ajouter les shortcodes aux pages marketing.', 'lightevents'); ?></li></ol><p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=lightevents-account')); ?>"><?php esc_html_e('Se connecter', 'lightevents'); ?></a> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lightevents-import')); ?>"><?php esc_html_e('Importer maintenant', 'lightevents'); ?></a> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lightevents-settings')); ?>"><?php esc_html_e('Réglages', 'lightevents'); ?></a></p></div>
                <div class="lightevents-admin-card"><h2><?php esc_html_e('Business model LightEvents', 'lightevents'); ?></h2><p><?php esc_html_e('Le plugin met en avant la billetterie officielle LightEvents: frais transparents, QR Code par email, check-in mobile, réservations temporaires, promo codes et paiements africains + internationaux.', 'lightevents'); ?></p><div class="lightevents-admin-pills"><span>Mobile Money</span><span>QR Check-in</span><span>Promo codes</span><span>SEO WordPress</span></div></div>
            </div>
        </div>
        <?php
    }

    public function account_page(): void {
        $notice = sanitize_text_field(wp_unslash($_GET['lightevents_notice'] ?? ''));
        $error = sanitize_text_field(wp_unslash($_GET['lightevents_error'] ?? ''));
        $email = sanitize_email(wp_unslash($_GET['email'] ?? get_option('lightevents_account_email', '')));
        $account = $this->current_lightevents_account();
        ?>
        <div class="wrap lightevents-admin-wrap">
            <?php $this->admin_hero(__('Compte LightEvents', 'lightevents'), __('Connectez votre site WordPress à un compte LightEvents. La création, l’import et la synchronisation nécessitent une connexion validée par code email.', 'lightevents')); ?>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <?php if ($account): ?>
                <div class="lightevents-admin-card lightevents-account-status">
                    <h2><?php esc_html_e('Site connecté', 'lightevents'); ?></h2>
                    <p><strong><?php echo esc_html($account['fullName'] ?: $account['email']); ?></strong><br><?php echo esc_html($account['email']); ?> · <?php echo esc_html($account['role']); ?></p>
                    <p><?php esc_html_e('Vous pouvez maintenant importer, synchroniser et gérer les événements LightEvents depuis WordPress.', 'lightevents'); ?></p>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=lightevents-import')); ?>"><?php esc_html_e('Importer / synchroniser', 'lightevents'); ?></a></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="lightevents_logout_account">
                        <?php wp_nonce_field('lightevents_logout_account'); ?>
                        <?php submit_button(__('Déconnecter ce site', 'lightevents'), 'secondary', 'submit', false); ?>
                    </form>
                </div>
            <?php else: ?>
                <div class="lightevents-admin-grid">
                    <form class="lightevents-admin-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <h2><?php esc_html_e('Créer un compte organisateur', 'lightevents'); ?></h2>
                        <input type="hidden" name="action" value="lightevents_register_account">
                        <?php wp_nonce_field('lightevents_register_account'); ?>
                        <label><?php esc_html_e('Nom complet', 'lightevents'); ?><input name="fullName" required></label>
                        <label><?php esc_html_e('Email', 'lightevents'); ?><input name="email" type="email" value="<?php echo esc_attr($email); ?>" required></label>
                        <label><?php esc_html_e('Téléphone', 'lightevents'); ?><input name="phone" placeholder="+225..."></label>
                        <label><?php esc_html_e('Méthode de reversement', 'lightevents'); ?><select name="payoutMethod"><option value="PAYPAL">PayPal</option><option value="BANK_TRANSFER">Virement bancaire</option></select></label>
                        <label><?php esc_html_e('Pays de reversement', 'lightevents'); ?><input name="payoutCountry" placeholder="Côte d’Ivoire, Allemagne..."></label>
                        <label><?php esc_html_e('Nom du compte de reversement', 'lightevents'); ?><input name="payoutAccountName"></label>
                        <label><?php esc_html_e('Email PayPal ou IBAN', 'lightevents'); ?><input name="payoutAccountRef"></label>
                        <?php submit_button(__('Créer et recevoir le code', 'lightevents'), 'primary'); ?>
                    </form>
                    <div class="lightevents-admin-card">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <h2><?php esc_html_e('Valider / se connecter', 'lightevents'); ?></h2>
                            <input type="hidden" name="action" value="lightevents_verify_account">
                            <?php wp_nonce_field('lightevents_verify_account'); ?>
                            <label><?php esc_html_e('Email du compte', 'lightevents'); ?><input name="email" type="email" value="<?php echo esc_attr($email); ?>" required></label>
                            <label><?php esc_html_e('Code reçu par email', 'lightevents'); ?><input name="code" inputmode="numeric" required></label>
                            <?php submit_button(__('Valider et connecter WordPress', 'lightevents'), 'primary'); ?>
                        </form>
                        <hr>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <h2><?php esc_html_e('J’ai déjà un compte', 'lightevents'); ?></h2>
                            <input type="hidden" name="action" value="lightevents_request_login_code">
                            <?php wp_nonce_field('lightevents_request_login_code'); ?>
                            <label><?php esc_html_e('Email du compte', 'lightevents'); ?><input name="email" type="email" value="<?php echo esc_attr($email); ?>" required></label>
                            <?php submit_button(__('Recevoir un code de connexion', 'lightevents'), 'secondary'); ?>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_register_account(): void {
        if (!current_user_can('manage_options')) { wp_die(__('Unauthorized', 'lightevents')); }
        check_admin_referer('lightevents_register_account');
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $response = $this->api->register_account([
            'fullName' => sanitize_text_field(wp_unslash($_POST['fullName'] ?? '')),
            'email' => $email,
            'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'whatsappNumber' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'role' => 'ORGANIZER',
            'payoutMethod' => strtoupper(sanitize_text_field(wp_unslash($_POST['payoutMethod'] ?? 'PAYPAL'))),
            'payoutCountry' => sanitize_text_field(wp_unslash($_POST['payoutCountry'] ?? '')),
            'payoutAccountName' => sanitize_text_field(wp_unslash($_POST['payoutAccountName'] ?? '')),
            'payoutAccountRef' => sanitize_text_field(wp_unslash($_POST['payoutAccountRef'] ?? '')),
        ]);
        if (is_wp_error($response)) { $this->redirect_account_error($response->get_error_message(), $email); }
        $message = $response['message'] ?? __('Compte créé. Entrez le code reçu par email pour connecter WordPress.', 'lightevents');
        if (!empty($response['codePreview'])) { $message .= ' ' . sprintf(__('Code de test: %s', 'lightevents'), $response['codePreview']); }
        $this->redirect_account_notice($message, $email);
    }

    public function handle_request_login_code(): void {
        if (!current_user_can('manage_options')) { wp_die(__('Unauthorized', 'lightevents')); }
        check_admin_referer('lightevents_request_login_code');
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $response = $this->api->request_login_code($email);
        if (is_wp_error($response)) { $this->redirect_account_error($response->get_error_message(), $email); }
        $message = $response['message'] ?? __('Code de connexion envoyé.', 'lightevents');
        if (!empty($response['codePreview'])) { $message .= ' ' . sprintf(__('Code de test: %s', 'lightevents'), $response['codePreview']); }
        $this->redirect_account_notice($message, $email);
    }

    public function handle_verify_account(): void {
        if (!current_user_can('manage_options')) { wp_die(__('Unauthorized', 'lightevents')); }
        check_admin_referer('lightevents_verify_account');
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
        $response = $this->api->verify_login($email, $code);
        if (is_wp_error($response)) {
            $response = $this->api->verify_account($email, $code);
        }
        if (is_wp_error($response)) { $this->redirect_account_error($response->get_error_message(), $email); }
        $this->store_lightevents_account($response);
        $this->redirect_account_notice(__('Connexion réussie. WordPress est connecté à LightEvents.', 'lightevents'), $email);
    }

    public function handle_logout_account(): void {
        if (!current_user_can('manage_options')) { wp_die(__('Unauthorized', 'lightevents')); }
        check_admin_referer('lightevents_logout_account');
        delete_option('lightevents_api_token');
        delete_option('lightevents_account_email');
        delete_option('lightevents_account_name');
        delete_option('lightevents_account_role');
        delete_option('lightevents_account_verified');
        $this->redirect_account_notice(__('Site déconnecté du compte LightEvents.', 'lightevents'));
    }

    public function import_page(): void {
        $notice = sanitize_text_field($_GET['lightevents_notice'] ?? '');
        $error = sanitize_text_field($_GET['lightevents_error'] ?? '');
        ?>
        <div class="wrap lightevents-admin-wrap">
            <?php $this->admin_hero(__('Import / Sync LightEvents', 'lightevents'), __('Importez un événement par ID ou URL, puis gardez WordPress synchronisé avec LightEvents.', 'lightevents')); ?>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <?php if (!$this->is_lightevents_connected()): ?>
                <?php $this->login_required_card(__('Connectez-vous avant d’importer ou synchroniser.', 'lightevents')); ?>
            <?php return; endif; ?>
            <div class="lightevents-admin-grid">
                <form class="lightevents-admin-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <h2><?php esc_html_e('Importer un événement', 'lightevents'); ?></h2>
                    <input type="hidden" name="action" value="lightevents_import_event">
                    <?php wp_nonce_field('lightevents_import_event'); ?>
                    <label><?php esc_html_e('Event ID ou URL LightEvents', 'lightevents'); ?><input class="regular-text" name="event_identifier" placeholder="123 ou https://app.lightevents.com/events/123" required></label>
                    <label><?php esc_html_e('Importer comme', 'lightevents'); ?><select name="post_status"><option value="publish"><?php esc_html_e('Publié', 'lightevents'); ?></option><option value="draft"><?php esc_html_e('Brouillon', 'lightevents'); ?></option></select></label>
                    <?php submit_button(__('Importer dans WordPress', 'lightevents')); ?>
                </form>
                <form class="lightevents-admin-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <h2><?php esc_html_e('Synchronisation catalogue', 'lightevents'); ?></h2>
                    <p><?php esc_html_e('Récupère tous les événements publiés LightEvents et crée/met à jour les posts WordPress liés.', 'lightevents'); ?></p>
                    <input type="hidden" name="action" value="lightevents_sync_events">
                    <?php wp_nonce_field('lightevents_sync_events'); ?>
                    <?php submit_button(__('Synchroniser maintenant', 'lightevents'), 'primary'); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function settings_page(): void {
        ?>
        <div class="wrap lightevents-admin-wrap">
            <?php $this->admin_hero(__('Settings', 'lightevents'), __('Configuration production: API, plateforme, token, page détail et options commerciales.', 'lightevents')); ?>
            <form method="post" action="options.php" class="lightevents-admin-card">
                <?php settings_fields('lightevents'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="lightevents_api_base">API LightEvents</label></th><td><input class="regular-text" id="lightevents_api_base" name="lightevents_api_base" value="<?php echo esc_attr(get_option('lightevents_api_base', 'https://lighteventstest.onrender.com/api')); ?>" placeholder="https://lighteventstest.onrender.com/api"></td></tr>
                    <tr><th scope="row"><label for="lightevents_platform_url">URL plateforme</label></th><td><input class="regular-text" id="lightevents_platform_url" name="lightevents_platform_url" value="<?php echo esc_attr(get_option('lightevents_platform_url', 'https://valere-merval.github.io/lightEventsFE')); ?>" placeholder="https://valere-merval.github.io/lightEventsFE"></td></tr>
                    <tr><th scope="row"><label for="lightevents_api_token">Token API / organisateur</label></th><td><input class="regular-text" id="lightevents_api_token" name="lightevents_api_token" value="<?php echo esc_attr(get_option('lightevents_api_token', '')); ?>" autocomplete="off"><p class="description">Recommandé: utilisez la page Compte / Connexion pour obtenir ce token après validation email. Ne mettez jamais un token GitHub ici.</p></td></tr>
                    <tr><th scope="row"><label for="lightevents_event_page_id">Page détail WordPress</label></th><td><?php wp_dropdown_pages(['name' => 'lightevents_event_page_id', 'selected' => absint(get_option('lightevents_event_page_id', 0)), 'show_option_none' => '— Utiliser les posts importés ou rediriger vers LightEvents —']); ?><p class="description">Une page contenant [lightevents_event_from_query] peut servir de page détail dynamique.</p></td></tr>
                    <tr><th scope="row"><label for="lightevents_default_currency">Devise par défaut</label></th><td><input id="lightevents_default_currency" name="lightevents_default_currency" value="<?php echo esc_attr(get_option('lightevents_default_currency', 'XOF')); ?>"></td></tr>
                    <tr><th scope="row"><label for="lightevents_payment_methods">Méthodes de paiement</label></th><td><input class="regular-text" id="lightevents_payment_methods" name="lightevents_payment_methods" value="<?php echo esc_attr(get_option('lightevents_payment_methods', 'ORANGE_MONEY,MTN_MONEY,WAVE,AIRTEL_MONEY,MOOV_MONEY,STRIPE,PAYPAL')); ?>"></td></tr>
                    <tr><th scope="row"><label for="lightevents_fee_label">Message frais</label></th><td><input class="regular-text" id="lightevents_fee_label" name="lightevents_fee_label" value="<?php echo esc_attr(get_option('lightevents_fee_label', 'Frais plateforme LightEvents: 4,5% inclus dans le prix affiché')); ?>"></td></tr>
                    <tr><th scope="row"><label for="lightevents_auto_sync">Auto-sync</label></th><td><select id="lightevents_auto_sync" name="lightevents_auto_sync"><option value="daily" <?php selected(get_option('lightevents_auto_sync', 'daily'), 'daily'); ?>>Daily</option><option value="off" <?php selected(get_option('lightevents_auto_sync'), 'off'); ?>>Off</option></select></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function shortcodes_page(): void {
        ?>
        <div class="wrap lightevents-admin-wrap"><?php $this->admin_hero(__('Shortcodes', 'lightevents'), __('Copiez-collez ces blocs dans vos pages WordPress.', 'lightevents')); ?>
        <div class="lightevents-admin-card"><ul class="lightevents-code-list"><li><code>[lightevents_events]</code> — grille responsive</li><li><code>[lightevents_events view="agenda" country="Côte d'Ivoire" category="business"]</code></li><li><code>[lightevents_events view="calendar" limit="30"]</code></li><li><code>[lightevents_event id="123"]</code></li><li><code>[lightevents_checkout event="123"]</code></li><li><code>[lightevents_event_from_query]</code> — page détail dynamique</li></ul></div></div>
        <?php
    }

    public function wizard_page(): void {
        ?>
        <div class="wrap lightevents-onboarding"><div class="lightevents-onboarding-panel"><a class="lightevents-close" href="<?php echo esc_url(admin_url('admin.php?page=lightevents-dashboard')); ?>">×</a><h1><?php esc_html_e('Bienvenue dans LightEvents', 'lightevents'); ?></h1><p><?php esc_html_e('Installez une billetterie type Eventbrite, optimisée pour l’Afrique et les organisateurs: import, Mobile Money, QR check-in, promo codes, réservations, SEO WordPress.', 'lightevents'); ?></p><div class="lightevents-onboarding-tiles"><a href="<?php echo esc_url(admin_url('admin.php?page=lightevents-account')); ?>"><span class="dashicons dashicons-admin-users"></span><strong>1. Compte LightEvents</strong><small>Créer un compte ou se connecter par code email</small></a><a href="<?php echo esc_url(admin_url('admin.php?page=lightevents-import')); ?>"><span class="dashicons dashicons-download"></span><strong>2. Importer / Sync</strong><small>Créer les pages événement WordPress</small></a><a href="<?php echo esc_url(admin_url('admin.php?page=lightevents-shortcodes')); ?>"><span class="dashicons dashicons-shortcode"></span><strong>3. Publier</strong><small>Grille, agenda, checkout</small></a></div><p><a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('admin.php?page=lightevents-account')); ?>"><?php esc_html_e('Se connecter', 'lightevents'); ?></a></p></div></div>
        <?php
    }

    public function support_page(): void {
        ?><div class="wrap lightevents-admin-wrap"><?php $this->admin_hero(__('Support & Help', 'lightevents'), __('Guide rapide pour une mise en production propre.', 'lightevents')); ?><div class="lightevents-admin-card"><h2>Checklist production</h2><ul><li>API HTTPS stable configurée.</li><li>Token organisateur LightEvents dans les réglages.</li><li>CORS backend autorise le domaine WordPress si checkout externe.</li><li>Emails transactionnels actifs pour QR tickets.</li><li>Moyens de paiement GetMiPay/Stripe/PayPal testés.</li></ul></div></div><?php
    }

    private function current_lightevents_account(): ?array {
        $token = trim((string) get_option('lightevents_api_token', ''));
        $email = trim((string) get_option('lightevents_account_email', ''));
        if ($token === '' || $email === '') { return null; }
        return [
            'email' => $email,
            'fullName' => (string) get_option('lightevents_account_name', ''),
            'role' => (string) get_option('lightevents_account_role', 'ORGANIZER'),
            'verified' => (bool) get_option('lightevents_account_verified', false),
        ];
    }

    private function is_lightevents_connected(): bool {
        return $this->current_lightevents_account() !== null;
    }

    private function store_lightevents_account(array $account): void {
        update_option('lightevents_api_token', sanitize_text_field((string)($account['apiToken'] ?? '')));
        update_option('lightevents_account_email', sanitize_email((string)($account['email'] ?? '')));
        update_option('lightevents_account_name', sanitize_text_field((string)($account['fullName'] ?? '')));
        update_option('lightevents_account_role', sanitize_text_field((string)($account['role'] ?? 'ORGANIZER')));
        update_option('lightevents_account_verified', !empty($account['verified']) ? '1' : '0');
    }

    private function login_required_card(string $message): void {
        ?>
        <div class="lightevents-admin-card lightevents-login-required">
            <h2><?php esc_html_e('Connexion LightEvents requise', 'lightevents'); ?></h2>
            <p><?php echo esc_html($message); ?></p>
            <p><?php esc_html_e('Créez un compte LightEvents ou connectez-vous avec votre email. Après validation du code, WordPress recevra le token organisateur nécessaire aux actions privées.', 'lightevents'); ?></p>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=lightevents-account')); ?>"><?php esc_html_e('Créer un compte / se connecter', 'lightevents'); ?></a></p>
        </div>
        <?php
    }

    private function require_lightevents_connection(string $page = 'lightevents-account'): void {
        if ($this->is_lightevents_connected()) { return; }
        wp_safe_redirect(add_query_arg('lightevents_error', __('Connectez-vous à LightEvents avant de continuer.', 'lightevents'), admin_url('admin.php?page=' . $page)));
        exit;
    }

    public function guard_event_editor($screen): void {
        if (!is_admin() || !$screen || ($screen->post_type ?? '') !== 'lightevents_event') { return; }
        if (!in_array($screen->base ?? '', ['post', 'post-new'], true)) { return; }
        if ($this->is_lightevents_connected()) { return; }
        wp_safe_redirect(add_query_arg('lightevents_error', __('Connectez-vous à LightEvents avant de créer ou modifier un événement.', 'lightevents'), admin_url('admin.php?page=lightevents-account')));
        exit;
    }

    private function redirect_account_notice(string $message, string $email = ''): void {
        $args = ['lightevents_notice' => $message];
        if ($email !== '') { $args['email'] = $email; }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=lightevents-account')));
        exit;
    }

    private function redirect_account_error(string $message, string $email = ''): void {
        $args = ['lightevents_error' => $message];
        if ($email !== '') { $args['email'] = $email; }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=lightevents-account')));
        exit;
    }

    public function handle_import_event(): void {
        if (!current_user_can('manage_options')) { wp_die(__('Unauthorized', 'lightevents')); }
        check_admin_referer('lightevents_import_event');
        $this->require_lightevents_connection('lightevents-import');
        $id = $this->extract_event_id(sanitize_text_field(wp_unslash($_POST['event_identifier'] ?? '')));
        $status = sanitize_key($_POST['post_status'] ?? get_option('lightevents_import_status', 'publish'));
        $event = $id ? $this->api->event($id) : new WP_Error('missing_id', __('ID événement invalide.', 'lightevents'));
        $notice = is_wp_error($event) ? $event->get_error_message() : sprintf(__('Événement #%s importé.', 'lightevents'), $id);
        if (!is_wp_error($event) && is_array($event)) { $this->upsert_event_post($event, $status); }
        wp_safe_redirect(add_query_arg('lightevents_notice', rawurlencode($notice), admin_url('admin.php?page=lightevents-import')));
        exit;
    }

    public function handle_sync_events(): void {
        if (!current_user_can('manage_options')) { wp_die(__('Unauthorized', 'lightevents')); }
        check_admin_referer('lightevents_sync_events');
        $this->require_lightevents_connection('lightevents-import');
        $count = $this->sync_events();
        wp_safe_redirect(add_query_arg('lightevents_notice', rawurlencode(sprintf(__('%d événements synchronisés.', 'lightevents'), $count)), admin_url('admin.php?page=lightevents-import')));
        exit;
    }

    public function sync_events(): int {
        if (get_option('lightevents_auto_sync', 'daily') === 'off' && !is_admin()) { return 0; }
        $events = $this->api->events(['publishedOnly' => 'true']);
        if (is_wp_error($events) || !is_array($events)) { return 0; }
        $count = 0;
        foreach ($events as $event) {
            if (is_array($event) && !empty($event['id'])) { $this->upsert_event_post($event, get_option('lightevents_import_status', 'publish')); $count++; }
        }
        update_option('lightevents_last_sync_at', current_time('mysql'));
        return $count;
    }

    private function upsert_event_post(array $event, string $status = 'publish'): int {
        $external_id = (string)($event['id'] ?? '');
        $existing = get_posts(['post_type' => 'lightevents_event', 'meta_key' => '_lightevents_event_id', 'meta_value' => $external_id, 'fields' => 'ids', 'posts_per_page' => 1]);
        $postarr = [
            'post_type' => 'lightevents_event',
            'post_status' => in_array($status, ['publish', 'draft', 'private'], true) ? $status : 'publish',
            'post_title' => sanitize_text_field($event['title'] ?? ('LightEvents #' . $external_id)),
            'post_content' => wp_kses_post((string)($event['description'] ?? '')) . "\n\n" . '[lightevents_event id="' . esc_attr($external_id) . '"]',
            'post_excerpt' => wp_trim_words(wp_strip_all_tags((string)($event['description'] ?? '')), 32),
        ];
        if ($existing) { $postarr['ID'] = (int)$existing[0]; $post_id = wp_update_post($postarr, true); }
        else { $post_id = wp_insert_post($postarr, true); }
        if (is_wp_error($post_id)) { return 0; }
        foreach ($this->event_meta($event) as $key => $value) { update_post_meta((int)$post_id, $key, $value); }
        $cats = array_filter(array_map('trim', explode(',', (string)($event['categories'] ?? ($event['category'] ?? '')))));
        if ($cats) { wp_set_object_terms((int)$post_id, $cats, 'lightevents_category', false); }
        return (int)$post_id;
    }

    private function event_meta(array $event): array {
        return [
            '_lightevents_event_id' => (string)($event['id'] ?? ''),
            '_lightevents_starts_at' => (string)($event['startsAt'] ?? ''),
            '_lightevents_ends_at' => (string)($event['endsAt'] ?? ''),
            '_lightevents_city' => (string)($event['city'] ?? ''),
            '_lightevents_country' => (string)($event['country'] ?? ''),
            '_lightevents_venue' => (string)($event['venueName'] ?? ''),
            '_lightevents_cover' => (string)($event['coverImageUrl'] ?? ($event['generatedImageUrl'] ?? '')),
            '_lightevents_payload' => wp_json_encode($event),
        ];
    }

    private function extract_event_id(string $value): int {
        if (preg_match('/(\d+)(?:\D*)$/', $value, $m)) { return absint($m[1]); }
        return absint($value);
    }

    public function event_columns(array $columns): array {
        $columns['lightevents_id'] = __('LightEvents ID', 'lightevents');
        $columns['lightevents_date'] = __('Date', 'lightevents');
        $columns['lightevents_location'] = __('Lieu', 'lightevents');
        return $columns;
    }

    public function event_column_content(string $column, int $post_id): void {
        if ($column === 'lightevents_id') { echo esc_html(get_post_meta($post_id, '_lightevents_event_id', true)); }
        if ($column === 'lightevents_date') { echo esc_html(get_post_meta($post_id, '_lightevents_starts_at', true)); }
        if ($column === 'lightevents_location') { echo esc_html(trim(get_post_meta($post_id, '_lightevents_city', true) . ', ' . get_post_meta($post_id, '_lightevents_country', true), ', ')); }
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
        $promo_code = sanitize_text_field(wp_unslash($_POST['promoCode'] ?? ''));
        $payment_otp = sanitize_text_field(wp_unslash($_POST['paymentOtp'] ?? ''));

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
            'promoCode' => $promo_code ?: null,
        ]);

        if (is_wp_error($reservation)) {
            wp_send_json_error(['message' => $reservation->get_error_message(), 'details' => $reservation->get_error_data()], 502);
        }

        if ($mode !== 'pay') {
            wp_send_json_success(['message' => __('Réservation enregistrée. Le QR ticket sera envoyé par email après paiement ou confirmation selon les règles LightEvents.', 'lightevents'), 'reservation' => $reservation]);
        }

        $event = $this->api->event($event_id);
        $amount = $this->reservation_amount($reservation);
        if ($amount === null) { $amount = $this->ticket_amount(is_wp_error($event) ? [] : $event, $ticket_id, $quantity); }
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
            'payerName' => $buyer_name,
            'payerEmail' => $buyer_email,
            'otp' => $payment_otp,
        ]);

        if (is_wp_error($checkout)) {
            wp_send_json_error(['message' => $checkout->get_error_message(), 'reservation' => $reservation], 502);
        }

        wp_send_json_success(['message' => __('Paiement initialisé. Après confirmation, les tickets QR seront envoyés par email.', 'lightevents'), 'reservation' => $reservation, 'checkout' => $checkout]);
    }

    private function reservation_amount($reservation): ?float {
        if (!is_array($reservation)) { return null; }
        foreach ([['reservation','grossAmount'], ['grossAmount']] as $path) {
            $value = count($path) === 2 ? ($reservation[$path[0]][$path[1]] ?? null) : ($reservation[$path[0]] ?? null);
            if ($value !== null) { return (float)$value; }
        }
        return null;
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
                return (string)($ticket['currency'] ?? get_option('lightevents_default_currency', 'XOF'));
            }
        }
        return (string)get_option('lightevents_default_currency', 'XOF');
    }

    private function admin_hero(string $title, string $subtitle): void {
        echo '<div class="lightevents-admin-hero"><div><h1>' . esc_html($title) . '</h1><p>' . esc_html($subtitle) . '</p></div><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=lightevents-wizard')) . '">' . esc_html__('Wizard', 'lightevents') . '</a></div>';
    }

    private function metric(string $label, string $value, string $hint): string {
        return '<div class="lightevents-admin-card metric"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong><small>' . esc_html($hint) . '</small></div>';
    }
}
