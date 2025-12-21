(function (wp) {
    const { registerBlockType } = wp.blocks;
    const { createElement: el } = wp.element;
    const { InspectorControls } = wp.blockEditor;
    const { PanelBody, RangeControl, TextControl } = wp.components;
    const { __ } = wp.i18n;

    registerBlockType('aivesese/cart-recommendations', {
        title: __('AI Cart Recommendations', 'ai-vector-search-semantic'),
        icon: 'cart',
        category: 'widgets',
        attributes: {
            title: {
                type: 'string',
                default: 'You might also like',
            },
            limit: {
                type: 'number',
                default: 4,
            },
            columns: {
                type: 'number',
                default: 4,
            },
            className: {
                type: 'string',
                default: '',
            },
        },
        edit: function (props) {
            const { attributes, setAttributes } = props;

            return el(
                'div',
                { className: props.className },
                el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        { title: __('Settings', 'ai-vector-search-semantic'), initialOpen: true },
                        el(TextControl, {
                            label: __('Title', 'ai-vector-search-semantic'),
                            value: attributes.title,
                            onChange: function (value) {
                                setAttributes({ title: value });
                            },
                        }),
                        el(RangeControl, {
                            label: __('Limit', 'ai-vector-search-semantic'),
                            value: attributes.limit,
                            min: 1,
                            max: 12,
                            onChange: function (value) {
                                setAttributes({ limit: value });
                            },
                        }),
                        el(RangeControl, {
                            label: __('Columns', 'ai-vector-search-semantic'),
                            value: attributes.columns,
                            min: 1,
                            max: 6,
                            onChange: function (value) {
                                setAttributes({ columns: value });
                            },
                        })
                    )
                ),
                el(
                    'div',
                    { className: 'aivesese-block-placeholder' },
                    el('strong', {}, attributes.title || __('AI Cart Recommendations', 'ai-vector-search-semantic')),
                    el(
                        'p',
                        {},
                        __('Cart recommendations will render on the frontend.', 'ai-vector-search-semantic')
                    )
                )
            );
        },
        save: function () {
            return null;
        },
    });
})(window.wp);
