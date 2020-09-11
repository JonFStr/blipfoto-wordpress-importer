<?php

defined( 'ABSPATH' ) or die();

global $blipfoto_importer_main;
$blipfoto_importer_main = new blipfoto_importer_main;

class blipfoto_importer_main {

    const CURL_SIGNIN = "https://www.blipfoto.com/account/signin";
    const CURL_ENTRY = "https://www.blipfoto.com/entry/";
    const CURL_DOWNLOAD = "/download/";
    const CURL_OPTS = array(
        CURLOPT_COOKIESESSION  => TRUE,
        CURLOPT_COOKIEFILE     => "",
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_HEADER         => 0,
        CURLOPT_FOLLOWLOCATION => FALSE,
    );
    const CURL_CSRF_ELEMENT_REGEX = "#<input .*? name=\"csrf\" .*? />#";
    const CURL_CSRF_TOKEN_REGEX = "/value=\"(.*?)\"/";
    const CURL_IMAGE_REGEX = "/Location: (.*)/";

    var $curl;

    function __construct() {
        add_action( 'admin_menu', array( $this, 'add_page' ) );
    }

    function add_page() {
        add_management_page(
            'Blipfoto Importer',
            'Blipfoto Importer',
            'import',
            'blipfoto-import',
            array( $this, 'render_page' )
            );
    }

    function init_curl() {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, blipfoto_importer_main::CURL_OPTS);

        //login
        curl_setopt($this->curl, CURLOPT_URL, blipfoto_importer_main::CURL_SIGNIN);
        $html_login = curl_exec($this->curl);

        //grep csrf token
        $matches = array();
        if(!preg_match(blipfoto_importer_main::CURL_CSRF_ELEMENT_REGEX, $html_login, $matches))
            throw new Exception("Login failed");
        $csrf_element = $matches[0];
        $matches = array();
        if(!preg_match(blipfoto_importer_main::CURL_CSRF_TOKEN_REGEX, $csrf_element, $matches))
            throw new Exception("Login failed");
        $csrf = $matches[1];

        //perform login
        $login_params = array(
            'email'    => blipfoto_importer::option('orig-email'),
            'password' => blipfoto_importer::option('orig-passwd'),
            'csrf'     => $csrf,
            'redir'    => '',
        );
        $login_opts = array(
            CURLOPT_POST => TRUE,
            CURLOPT_POSTFIELDS => http_build_query($login_params),
        );
        curl_setopt_array($this->curl, $login_opts);
        curl_exec($this->curl);
        if(curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE) != 302) //on successful login, one gets redirected (302)
            throw new Exception("Login incorrect");

        //remove POST type for future queries
        $reset_curlopts = array(
            CURLOPT_HTTPGET => TRUE,
            CURLOPT_FOLLOWLOCATION => TRUE,
        );
        curl_setopt_array($this->curl, $reset_curlopts);
    }

    function get_image($entry, $post_id, $post_title) {
        if(! blipfoto_importer::option('orig-img')) //return API photo, if not otherwise requested (in settings)
            media_sideload_image( $entry->data('image_urls.stdres'), $post_id, $post_title );

        if(! $this->curl) // if not yet done, initialize curl
            $this->init_curl();

        //fetch image url
        curl_setopt($this->curl, CURLOPT_URL, blipfoto_importer_main::CURL_ENTRY . $entry->data('entry.entry_id') . blipfoto_importer_main::CURL_DOWNLOAD);
        $image_contents = curl_exec($this->curl);

        //if the image could not be downloaded, use std & display error
        if(curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE) !== 200) {
            echo "Couldn't fetch original image for entry with title " . $entry->data('entry.title');
            media_sideload_image( $entry->data('image_urls.stdres'), $post_id, $post_title );
        }

        // ----- put image in wp_upload and attach it to post
        //extract image information & folder
        $imageinfo = getimagesizefromstring($image_contents);
        $extension = image_type_to_extension($imageinfo[2]);
        $filename = $entry->data('entry.entry_id') . $extension;
        $dir = wp_upload_dir();
        $cache_path = $dir['path'];
        $cache_url = $dir['url'];

        $image['path'] = $cache_path;
        $image['url'] = $cache_url;

        //write file
        $new_filename = wp_unique_filename($cache_path, $filename);
        if(is_writable($cache_path) && $image_contents) { //if we can write & have something to write
            file_put_contents($cache_path . '/' . $new_filename, $image_contents);

            $image['type'] = $imageinfo['mime'];

            $image['filename'] = $new_filename;
            $image['full_path'] = $cache_path . '/' . $new_filename;
            $image['full_url'] = $cache_url . '/' . $new_filename;
        } else {
            echo "Couldn't store image in " . wp_upload_dir()['path'] . ". Is it writeable?";
            media_sideload_image( $entry->data('image_urls.stdres'), $post_id, $post_title );
        }

        //create attachment
        $attachment = array(
            'guid' => $image['full_path'],
            'post_type' => 'attachment',
            'post_title' => $post_title,
            'post_content' => '',
            'post_parent' => $post_id,
            'post_status' => 'publish',
            'post_mime_type' => $image['type'],
            'post_author'   => 1
        );

        //attach to post
        $attach_id = wp_insert_attachment( $attachment, $image['full_path'], $post_id );
        //update metadata
        if ( !is_wp_error($attach_id) ) {
            /** Admin Image API for metadata updating */
            require_once(ABSPATH . '/wp-admin/includes/image.php');
            wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $image['full_path']));
        }
    }

    function render_page() {
        echo '<div class="wrap">';
        echo '<h1>Blipfoto Importer</h1>';
        if ( ! blipfoto_importer::options_saved() ) {
            echo '<p>Please <a href="' . admin_url( 'options-general.php?page=blipfoto-importer-settings' ) . '">go to the settings page</a> to create the app and set the options</p>';
        } else {
            $client = blipfoto_importer::new_client();
            try {
                $response = $client->get(
                    'user/profile',
                    array(
                        'return_details' => 1
                        )
                    );
            } catch ( Exception $e ) {
                echo $e->getCode() . $e->getMessage();
                echo '<p>Could not connect to Blipfoto with the settings you have entered. Please try again or check your <a href="' . admin_url( 'options-general.php?page=blipfoto-importer-settings' ) . '">settings</a>.</p></div>';
                return;
            }
            $total_entries = $response->data( 'details.entry_total' );
            $page_size     = blipfoto_importer::option( 'num-entries' );
            if ( $total_entries > 0 ) {
                echo '<p>You have ' . $total_entries . ' entries.</p>';
                if ( isset( $_POST[ 'blipfoto-import-go' ] ) and wp_verify_nonce( $_POST[ 'blipfoto_importer_nonce' ], 'blipfoto-importer-nonce' ) ) {
                    $fetch        = min( $page_size, $total_entries );
                    $num_to_fetch = 0;
                    $to_fetch     = array();
                    $page         = 0;
                    while ( $num_to_fetch < $fetch ) {
                        $journal = $client->get(
                            'entries/journal',
                            array(
                                'page_size'  => $page_size,
                                'page_index' => $page
                                )
                            );
                        $entries = $journal->data( 'entries' );
                        foreach ( $entries as $entry ) {
                            $entry_id = $entry[ 'entry_id_str' ];
                            $args = array(
                                'post_type'      => blipfoto_importer::option( 'post-type' ),
                                'post_status'    => 'any',
                                'posts_per_page' => 1,
                                'meta_query'     => array(
                                    array(
                                        'key'   => 'blipfoto-entry-id',
                                        'value' => $entry_id
                                        )
                                    )
                                );
                            $post = get_posts( $args );
                            if ( ! $post ) {
                                $to_fetch[] = $entry_id;
                                $num_to_fetch++;
                            }
                        }
                        $page++;
                    }
                    if ( $num_to_fetch ) {
                        foreach ( $to_fetch as $item ) {
                            $entry = $client->get(
                                'entry',
                                array(
                                    'entry_id'          => $item,
                                    'return_details'    => 1,
                                    'return_image_urls' => 1
                                    )
                                );
                            if ( ! $title = $entry->data( 'entry.title' ) ) {
                                $title = 'Untitled';
                            }
                            $content = $entry->data('details.description_html');
                            if(blipfoto_importer::option('keep-tags') !== "*")
                                $content = strip_tags($content, blipfoto_importer::option('keep-tags'));
                            $date    = $entry->data( 'entry.date' ) . ' 00:00:00';
                            $post_data = array(
                                'post_type'     => blipfoto_importer::option( 'post-type' ),
                                'post_status'   => blipfoto_importer::option( 'post-status' ),
                                'post_date'     => $date,
                                'post_date_gmt' => get_gmt_from_date( $date ),
                                'post_title'    => $title,
                                'post_content'  => $content
                            );
                            if(blipfoto_importer::option('post-category'))
                                $post_data['post_category'] = array(get_cat_ID(blipfoto_importer::option('post-category')));
                            if ( $id = wp_insert_post( $post_data ) ) {
                                add_action( 'add_attachment', array( $this, 'set_featured_image' ) );
                                $this->get_image($entry, $id, $title);
                                remove_action( 'add_attachment', array( $this, 'set_featured_image' ) );
                                if ( blipfoto_importer::option( 'auto-insert' ) ) {
                                    $img_id = get_post_thumbnail_id( $id );
                                    $content = '<img class="aligncenter size-full wp-image-' . $img_id . '" src="' . $img_url . '" alt="' . esc_attr( $title ) . '" />' . $content;
                                    $update_data = array(
                                        'ID'           => $id,
                                        'post_content' => $content
                                    );
                                    wp_update_post( $update_data );
                                }
                                add_post_meta( $id, 'blipfoto-entry-id', $item );
                                echo '<p>Created <a href="' . get_permalink( $id ) . '" target="_blank">' . get_the_title( $id ) . '</a> (' . $id . ') for entry ' . $item . '</p>';
                            }
                        }
                    } else {
                        echo '<p>No more entries to fetch.</p>';
                    }
                }
                ?>
                <p>Click to import <?php echo $page_size; ?> entries...</p>
                <form method="post" action="<?php echo admin_url( 'tools.php?page=blipfoto-import' ); ?>">
                    <?php wp_nonce_field( 'blipfoto-importer-nonce', 'blipfoto_importer_nonce' ); ?>
                    <input class="button-primary" type="submit" name="blipfoto-import-go" value="Go!">
                </form>
                <?php
            } else {
                '<p>You have no entries.</p>';
            }
        }
        echo '</div>';
    }

    function set_featured_image( $id ) {
        $p = get_post( $id );
        update_post_meta( $p->post_parent, '_thumbnail_id', $id );
    }

}
