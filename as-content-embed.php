<?php
/**
 * Plugin Name:       AS Content Embed
 * Plugin URI:        https://mirschel.biz
 * Description:       Ermöglicht das Einbinden von WordPress-Seiten und Beiträgen via Script auf externen Seiten. Fügt einen Kopieren-Button in den Admin-Übersichten hinzu und stellt eine REST-API-Endpunkt für den Inhalt bereit.
 * Version:           1.3.1
 * Requires PHP:      7.4
 * Author:            Marc Mirschel
 * Author URI:        https://mirschel.biz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       as-content-embed
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direkten Zugriff verhindern
}

class AS_Content_Embed {

    public function __construct() {
        // Admin-Spalten hinzufügen
        add_filter( 'manage_pages_columns', array( $this, 'add_embed_column' ) );
        add_action( 'manage_pages_custom_column', array( $this, 'render_embed_column' ), 10, 2 );
        add_filter( 'manage_posts_columns', array( $this, 'add_embed_column' ) );
        add_action( 'manage_posts_custom_column', array( $this, 'render_embed_column' ), 10, 2 );

        // Admin-Assets laden
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // REST-API und CORS initialisieren
        add_action( 'rest_api_init', array( $this, 'register_rest_endpoint' ) );
        add_action( 'rest_api_init', array( $this, 'enable_cors' ) );

        // Cache leeren, wenn ein Beitrag gespeichert wird
        add_action( 'save_post', array( $this, 'clear_cache_on_post_save' ) );
        
        // Shortcode registrieren
        add_action( 'init', array( $this, 'register_shortcode' ) );
        
        // Hooks für die Einstellungsseite
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_plugin_settings' ) );
        
        // Handler für die dynamische Observer-Skript-Datei
        add_action( 'init', array( $this, 'handle_observer_js_request' ) );
    }

    /**
     * Fügt eine neue Seite unter "Einstellungen" im Admin-Menü hinzu.
     */
    public function add_settings_page() {
        add_options_page(
            'AS Content Embed Settings',
            'AS Content Embed',
            'manage_options',
            'as-content-embed',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Registriert die Einstellungen, Sektionen und Felder.
     */
    public function register_plugin_settings() {
        // Sektion für CORS-Einstellungen
        register_setting(
            'as_embed_settings_group',
            'as_embed_allowed_origins',
            array( $this, 'sanitize_origins_list' )
        );

        add_settings_section(
            'as_embed_main_section',
            __( 'CORS-Einstellungen', 'as-content-embed' ),
            array( $this, 'render_settings_section' ),
            'as-content-embed'
        );

        add_settings_field(
            'as_embed_allowed_origins_field',
            __( 'Erlaubte Domains', 'as-content-embed' ),
            array( $this, 'render_allowed_origins_field' ),
            'as-content-embed',
            'as_embed_main_section'
        );

        // Sektion für die finalen Einbettungs-Codes
        add_settings_section(
            'as_embed_script_info_section',
            __( 'Einbettungs-Codes', 'as-content-embed' ),
            array( $this, 'render_script_info_section' ),
            'as-content-embed'
        );

        add_settings_field(
            'as_embed_html_script_tag_field',
            __( 'Finales Skript-Tag (Empfohlen)', 'as-content-embed' ),
            array( $this, 'render_html_script_tag_field' ),
            'as-content-embed',
            'as_embed_script_info_section'
        );
        
        add_settings_field(
            'as_embed_full_script_field',
            __( 'Vollständiges JS (Manuell)', 'as-content-embed' ),
            array( $this, 'render_full_script_field' ),
            'as-content-embed',
            'as_embed_script_info_section'
        );
    }

    /**
     * Rendert die HTML-Struktur der Einstellungsseite.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'as_embed_settings_group' );
                do_settings_sections( 'as-content-embed' );
                submit_button( __( 'Änderungen speichern', 'as-content-embed' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Rendert die Beschreibung der CORS-Sektion.
     */
    public function render_settings_section() {
        echo '<p>' . __( 'Tragen Sie hier die vollständigen Domains (inkl. https://) ein, die Inhalte von dieser Seite einbetten dürfen. Eine Domain pro Zeile.', 'as-content-embed' ) . '</p>';
    }
    
    /**
     * Rendert die Beschreibung der Skript-Sektion.
     */
    public function render_script_info_section() {
        echo '<p>' . __( 'Verwenden Sie diese Codes, um die Einbettungsfunktion auf Ihrer externen Seite zu aktivieren.', 'as-content-embed' ) . '</p>';
    }

    /**
     * Rendert das Textarea-Feld für die erlaubten Domains.
     */
    public function render_allowed_origins_field() {
        $option = get_option( 'as_embed_allowed_origins' );
        ?>
        <textarea id="as_embed_allowed_origins_field" name="as_embed_allowed_origins" rows="5" cols="50" class="large-text code"><?php echo esc_textarea( $option ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'Beispiel: https://www.meine-externe-seite.de', 'as-content-embed' ); ?>
        </p>
        <?php
    }

    /**
     * Rendert das Feld für den vollständigen, optimierten HTML-Script-Block.
     */
    public function render_html_script_tag_field() {
        $script_url = home_url( '/?as_content_embed=observer.js' );
        $origin_url = rtrim( home_url(), '/' );
        
        $html_block  = '<link rel="preconnect" href="' . esc_attr( $origin_url ) . '">' . "\n";
        $html_block .= '<link rel="dns-prefetch" href="' . esc_attr( $origin_url ) . '">' . "\n";
        $html_block .= '<script src="' . esc_attr( $script_url ) . '" async defer></script>';
        ?>
        <div class="as-embed-wrapper" style="max-width: 100%; width: 50em;">
             <p class="description" style="margin-bottom: 5px;">
                <?php esc_html_e( 'Fügen Sie diesen gesamten Block in den <head>-Bereich Ihrer externen Seite ein für die beste Performance.', 'as-content-embed' ); ?>
            </p>
            <textarea id="copy-html-script-tag" class="as-embed-input" rows="5" readonly style="font-family: monospace; white-space: pre; overflow-wrap: normal; overflow-x: scroll;"><?php echo esc_textarea( $html_block ); ?></textarea>
            <button type="button" class="as-embed-button" data-target="#copy-html-script-tag" aria-label="<?php esc_attr_e( 'HTML-Block kopieren', 'as-content-embed' ); ?>" style="top: 28px; height: calc(100% - 29px);">
                <span class="icon-default"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 18 20"><path d="M16 1h-3.278A1.992 1.992 0 0 0 11 0H7a1.993 1.993 0 0 0-1.722 1H2a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2Zm-3 14H5a1 1 0 0 1 0-2h8a1 1 0 0 1 0 2Zm0-4H5a1 1 0 0 1 0-2h8a1 1 0 1 1 0 2Zm0-5H5a1 1 0 0 1 0-2h2V2h4v2h2a1 1 0 1 1 0 2Z"/></svg></span>
                <span class="icon-success" style="display: none;"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 12"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 5.917 5.724 10.5 15 1.5"/></svg></span>
            </button>
        </div>
        <?php
    }
    
    /**
     * Rendert ein Textfeld mit dem vollständigen Observer-Skript für die manuelle Einbindung.
     */
    public function render_full_script_field() {
        $full_script = $this->get_observer_js();
        ?>
        <div class="as-embed-wrapper" style="max-width: 100%; width: 50em;">
            <p class="description" style="margin-bottom: 5px;">
                <?php esc_html_e( 'Alternative: Fügen Sie dieses Skript direkt vor dem schließenden </body>-Tag ein, falls Sie die Verlinkung per URL nicht nutzen können.', 'as-content-embed' ); ?>
            </p>
            <textarea id="copy-full-script" class="as-embed-input" rows="15" readonly style="font-family: monospace; white-space: pre; overflow-wrap: normal; overflow-x: scroll;"><?php echo esc_textarea( $full_script ); ?></textarea>
            <button type="button" class="as-embed-button" data-target="#copy-full-script" aria-label="<?php esc_attr_e( 'Vollständiges Skript kopieren', 'as-content-embed' ); ?>" style="top: 28px; height: calc(100% - 29px);">
                <span class="icon-default"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 18 20"><path d="M16 1h-3.278A1.992 1.992 0 0 0 11 0H7a1.993 1.993 0 0 0-1.722 1H2a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2Zm-3 14H5a1 1 0 0 1 0-2h8a1 1 0 0 1 0 2Zm0-4H5a1 1 0 0 1 0-2h8a1 1 0 1 1 0 2Zm0-5H5a1 1 0 0 1 0-2h2V2h4v2h2a1 1 0 1 1 0 2Z"/></svg></span>
                <span class="icon-success" style="display: none;"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 12"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 5.917 5.724 10.5 15 1.5"/></svg></span>
            </button>
        </div>
        <?php
    }

    /**
     * Bereinigt die Eingabe vor dem Speichern in der Datenbank.
     */
    public function sanitize_origins_list( $input ) {
        $lines = explode( "\n", $input );
        $sanitized_lines = [];

        foreach ( $lines as $line ) {
            $trimmed_line = trim( $line );
            $trimmed_line = rtrim( $trimmed_line, '/' );

            if ( ! empty( $trimmed_line ) ) {
                $sanitized_lines[] = esc_url_raw( $trimmed_line );
            }
        }
        return implode( "\n", array_filter( $sanitized_lines ) );
    }

    public function add_embed_column( $columns ) {
        $columns['as_embed'] = __( 'Embed', 'as-content-embed' );
        return $columns;
    }

    public function render_embed_column( $column_name, $post_id ) {
        if ( 'as_embed' === $column_name ) {
            $embed_placeholder = sprintf( '<div class="as-content-embed-placeholder" data-post-id="%d"></div>', $post_id );
            $shortcode = sprintf( '[as_content_embed id="%d"]', $post_id );
            ?>
            <div class="as-embed-wrapper">
                <label for="copy-placeholder-<?php echo esc_attr( $post_id ); ?>" class="as-embed-label-sr"><?php esc_html_e( 'Embed-Code (extern)', 'as-content-embed' ); ?></label>
                <input id="copy-placeholder-<?php echo esc_attr( $post_id ); ?>" type="text" class="as-embed-input" value="<?php echo esc_attr( $embed_placeholder ); ?>" readonly>
                <button type="button" class="as-embed-button" data-target="#copy-placeholder-<?php echo esc_attr( $post_id ); ?>" aria-label="<?php esc_attr_e( 'Embed-Code kopieren', 'as-content-embed' ); ?>">
                    <span class="icon-default"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 18 20"><path d="M16 1h-3.278A1.992 1.992 0 0 0 11 0H7a1.993 1.993 0 0 0-1.722 1H2a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2Zm-3 14H5a1 1 0 0 1 0-2h8a1 1 0 0 1 0 2Zm0-4H5a1 1 0 0 1 0-2h8a1 1 0 1 1 0 2Zm0-5H5a1 1 0 0 1 0-2h2V2h4v2h2a1 1 0 1 1 0 2Z"/></svg></span>
                    <span class="icon-success" style="display: none;"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 12"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 5.917 5.724 10.5 15 1.5"/></svg></span>
                </button>
            </div>

            <div class="as-embed-wrapper">
                <label for="copy-shortcode-<?php echo esc_attr( $post_id ); ?>" class="as-embed-label-sr"><?php esc_html_e( 'Shortcode (intern)', 'as-content-embed' ); ?></label>
                <input id="copy-shortcode-<?php echo esc_attr( $post_id ); ?>" type="text" class="as-embed-input" value="<?php echo esc_attr( $shortcode ); ?>" readonly>
                <button type="button" class="as-embed-button" data-target="#copy-shortcode-<?php echo esc_attr( $post_id ); ?>" aria-label="<?php esc_attr_e( 'Shortcode kopieren', 'as-content-embed' ); ?>">
                    <span class="icon-default"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 18 20"><path d="M16 1h-3.278A1.992 1.992 0 0 0 11 0H7a1.993 1.993 0 0 0-1.722 1H2a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2Zm-3 14H5a1 1 0 0 1 0-2h8a1 1 0 0 1 0 2Zm0-4H5a1 1 0 0 1 0-2h8a1 1 0 1 1 0 2Zm0-5H5a1 1 0 0 1 0-2h2V2h4v2h2a1 1 0 1 1 0 2Z"/></svg></span>
                    <span class="icon-success" style="display: none;"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 12"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 5.917 5.724 10.5 15 1.5"/></svg></span>
                </button>
            </div>
            <?php
        }
    }

    public function enqueue_admin_assets( $hook ) {
        $allowed_hooks = [
            'edit.php',
            'edit-pages.php',
            'settings_page_as-content-embed',
        ];

        if ( in_array( $hook, $allowed_hooks, true ) ) {
            wp_enqueue_style( 'as-embed-admin', plugin_dir_url( __FILE__ ) . 'assets/css/as-content-embed-admin.css', array(), '1.3.0' );
            wp_enqueue_script( 'as-embed-admin', plugin_dir_url( __FILE__ ) . 'assets/js/as-content-embed-admin.js', array(), '1.3.0', true );
        }
    }

    public function register_rest_endpoint() {
        register_rest_route( 'as-embed/v1', '/post/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_post_content' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function enable_cors() {
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
        add_filter( 'rest_pre_serve_request', array( $this, 'custom_cors_headers' ) );
    }

    public function custom_cors_headers( $value ) {
        if ( empty( $_SERVER['HTTP_ORIGIN'] ) ) {
            return $value;
        }

        $allowed_origins_raw = get_option( 'as_embed_allowed_origins', '' );
        $allowed_origins = array_filter( array_map( 'trim', explode( "\n", $allowed_origins_raw ) ) );
        $request_origin = $_SERVER['HTTP_ORIGIN'];
        $is_origin_allowed = in_array( $request_origin, $allowed_origins, true );

        if ( $is_origin_allowed ) {
            header( 'Access-Control-Allow-Origin: ' . esc_url( $request_origin ) );
            header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Vary: Origin' );
        }

        if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
            if ( $is_origin_allowed ) {
                status_header( 200 );
                exit;
            }
            return $value;
        }

        if ( ! $is_origin_allowed ) {
            return new WP_Error(
                'rest_forbidden_origin',
                __( 'Der Zugriff von dieser Domain ist nicht gestattet.', 'as-content-embed' ),
                array( 'status' => 403 )
            );
        }
        return $value;
    }
    
    public function get_post_content( $request ) {
        $post_id = (int) $request['id'];
        $content = $this->get_post_content_by_id( $post_id );

        if ( '' === $content ) {
            return new WP_Error( 'no_content', 'Inhalt nicht gefunden oder Zugriff verweigert.', array( 'status' => 404 ) );
        }

        $response_data = array( 'content' => $content );
        return rest_ensure_response( $response_data );
    }
    
    private function get_post_content_by_id( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return '';
        }

        $transient_key = 'as_embed_data_content_' . $post_id;
        $cached_content = get_transient( $transient_key );
        if ( false !== $cached_content ) {
            return $cached_content;
        }

        $content = $post->post_content;
        if ( class_exists( 'Elementor\Plugin' ) ) {
            $elementor_document = Elementor\Plugin::instance()->documents->get( $post_id );
            if ( $elementor_document && $elementor_document->is_built_with_elementor() ) {
                $content = Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $post_id, false );
            }
        }

        $content = apply_filters( 'the_content', $content );
        set_transient( $transient_key, $content, HOUR_IN_SECONDS );
        return $content;
    }

    public function clear_cache_on_post_save( $post_id ) {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        delete_transient( 'as_embed_data_content_' . $post_id );
    }

    public function register_shortcode() {
        add_shortcode( 'as_content_embed', array( $this, 'handle_shortcode' ) );
    }

    public function handle_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'as_content_embed' );
        $post_id = intval( $atts['id'] );

        if ( $post_id <= 0 ) {
            return '<p style="color: red;">' . __( 'Fehler: Es wurde keine gültige ID für den Inhalt angegeben.', 'as-content-embed' ) . '</p>';
        }

        if ( is_singular() && get_the_ID() === $post_id ) {
            return '<p style="color: red;">' . __( 'Fehler: Ein Beitrag kann sich nicht selbst einbetten.', 'as-content-embed' ) . '</p>';
        }
        
        $content = $this->get_post_content_by_id( $post_id );
        return '<div class="as-embedded-content">' . $content . '</div>';
    }
    
    /**
     * Prüft, ob das Observer-Skript angefragt wird und liefert es aus.
     */
    public function handle_observer_js_request() {
        if ( isset( $_GET['as_content_embed'] ) && $_GET['as_content_embed'] === 'observer.js' ) {
            header( 'Content-Type: application/javascript; charset=utf-8' );
            echo $this->get_observer_js();
            exit;
        }
    }

   /**
 * Erstellt den Inhalt des Observer-Skripts mit einem detaillierteren Skeleton-Loader.
 * @return string Das vollständige JavaScript.
 */
private function get_observer_js() {
    $api_base_url = rest_url( 'as-embed/v1/post/' );

    return <<<JS
(function() {
    'use strict';
    const API_BASE_URL = '{$api_base_url}';

    // HTML-Struktur mit mehr Zeilen und "spacer"-Klassen für Abstände
    const skeletonHTML = `
        <div class="skeleton-wrapper">
            <div class="skeleton-line skeleton-line--heading" style="width: 70%;"></div>
            <div class="skeleton-line" style="width: 95%;"></div>
            <div class="skeleton-line" style="width: 88%;"></div>
            <div class="skeleton-line" style="width: 92%;"></div>
            <div class="skeleton-line skeleton-line--spacer" style="width: 75%;"></div>
            <div class="skeleton-line" style="width: 96%;"></div>
            <div class="skeleton-line" style="width: 91%;"></div>
            <div class="skeleton-line" style="width: 97%;"></div>
            <div class="skeleton-line skeleton-line--spacer" style="width: 85%;"></div>
            <div class="skeleton-line" style="width: 93%;"></div>
            <div class="skeleton-line" style="width: 89%;"></div>
            <div class="skeleton-line" style="width: 60%;"></div>
        </div>
    `;
    
    // CSS mit neuer ".skeleton-line--spacer"-Klasse für den größeren Abstand
    const skeletonCSS = `
        .skeleton-wrapper { padding: 10px; }
        .skeleton-line {
            background-color: #e0e0e0;
            border-radius: 4px;
            margin-bottom: 12px;
            position: relative;
            overflow: hidden;
        }
        .skeleton-line::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            transform: translateX(-100%);
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            animation: skeleton-shimmer 1.5s infinite;
        }
        .skeleton-line--heading {
            height: 36px;
            margin-bottom: 24px;
        }
        .skeleton-line:not(.skeleton-line--heading) {
            height: 16px;
        }
        .skeleton-line--spacer {
            margin-bottom: 28px; /* 12px Standard-Abstand + 16px extra */
        }
        @keyframes skeleton-shimmer {
            100% {
                transform: translateX(100%);
            }
        }
    `;

    function addSkeletonStyles() {
        if (document.getElementById('as-embed-skeleton-styles')) return;
        const style = document.createElement('style');
        style.id = 'as-embed-skeleton-styles';
        style.textContent = skeletonCSS;
        document.head.appendChild(style);
    }

    function initEmbed(element) {
        if (element.dataset.embedProcessed === 'true') return;
        element.dataset.embedProcessed = 'true';
        
        addSkeletonStyles();

        const postId = element.dataset.postId;
        if (!postId) return;

        element.innerHTML = skeletonHTML;

        fetch(API_BASE_URL + postId)
            .then(response => {
                if (!response.ok) throw new Error('API request failed: ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.content) element.innerHTML = data.content;
                else throw new Error('Kein content-Feld in der API-Antwort.');
            })
            .catch(error => {
                console.error('Embed Error:', error);
                element.innerHTML = '<em style="color: red;">Inhalt konnte nicht geladen werden.</em>';
            });
    }

    function findAndInitPlaceholders(targetNode) {
        if (targetNode.nodeType !== Node.ELEMENT_NODE) return;
        if (targetNode.matches('.as-content-embed-placeholder')) initEmbed(targetNode);
        targetNode.querySelectorAll('.as-content-embed-placeholder').forEach(initEmbed);
    }

    const observer = new MutationObserver(mutations => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(newNode => findAndInitPlaceholders(newNode));
        });
    });

    observer.observe(document.body, { childList: true, subtree: true });
    findAndInitPlaceholders(document.body);
})();
JS;
}
}

new AS_Content_Embed();