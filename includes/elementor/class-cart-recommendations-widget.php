<?php

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class AIVectorSearch_Cart_Recommendations_Widget extends Widget_Base {

    public function get_name() {
        return 'aivesese_cart_recommendations';
    }

    public function get_title() {
        return 'AI Cart Recommendations';
    }

    public function get_icon() {
        return 'eicon-cart';
    }

    public function get_categories() {
        return ['general'];
    }

    protected function register_controls() {
        $this->start_controls_section('content_section', [
            'label' => 'Content',
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('title', [
            'label' => 'Title',
            'type' => Controls_Manager::TEXT,
            'default' => 'You might also like',
        ]);

        $this->add_control('limit', [
            'label' => 'Limit',
            'type' => Controls_Manager::NUMBER,
            'default' => 4,
            'min' => 1,
            'max' => 12,
        ]);

        $this->add_control('columns', [
            'label' => 'Columns',
            'type' => Controls_Manager::NUMBER,
            'default' => 4,
            'min' => 1,
            'max' => 6,
        ]);

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $title = sanitize_text_field((string) ($settings['title'] ?? 'You might also like'));
        $limit = isset($settings['limit']) ? (int) $settings['limit'] : 4;
        $columns = isset($settings['columns']) ? (int) $settings['columns'] : 4;

        echo AIVectorSearch_Recommendations::instance()->get_cart_recommendations_html([
            'title' => $title,
            'limit' => $limit,
            'columns' => $columns,
            'respect_setting' => false,
        ]);
    }
}
