<?php

namespace ImageFocus;

class ImageFocus
{
    public function __construct()
    {
        $this->addHooks();
    }

    /**
     * Make sure all hooks are being executed.
     */
    private function addHooks()
    {
        add_action('admin_init', [$this, 'loadTextDomain']);
        add_action('admin_post_thumbnail_html', [$this, 'addFocusFeatureImageEditorLink'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'loadScripts']);
    }

    /**
     * Enqueues all necessary CSS and Scripts
     */
    public function loadScripts()
    {
//        wp_enqueue_script('wp-api'); // @todo activate for backbone integration
//
//        wp_enqueue_script('jquery-focuspoint',
//            plugins_url('bower_components/jquery-focuspoint/js/jquery.focuspoint.min.js', dirname(__FILE__)),
//            ['jquery']); // @todo activate for feature thumbnail crop previews

        wp_enqueue_script('image-focus-js',
            plugins_url('js/image-focus.js', dirname(__FILE__)), ['jquery']);

        wp_register_style('image-focus-css', plugins_url('css/image-focus.css', dirname(__FILE__)));
        wp_enqueue_style('image-focus-css');
    }

    /**
     * Load the gettext plugin textdomain located in our language directory.
     */
    public function loadTextDomain()
    {
        load_plugin_textdomain(IMAGEFOCUS_TEXTDOMAIN, false, IMAGEFOCUS_LANGUAGES);
    }
}
