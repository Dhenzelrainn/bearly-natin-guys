<?php

return [
    // Only these explicitly curated, persisted rules are eligible for direct
    // image-policy matching. Other visual concerns stay in Admin review.
    'image_detection_rule_codes' => [
        'PROHIBITED_FIREARMS',
        'PROHIBITED_FIREARM_GUN_ALIAS',
        'PROHIBITED_ILLEGAL_DRUGS',
    ],

    /*
    | Runtime fallback terms for managed compliance rules. These are merged
    | with persisted rule configuration so existing installations receive
    | safe wording updates without rewriting migration history.
    */
    'category_mismatch_runtime_terms' => [
        'CATEGORY_MISMATCH_ELECTRONICS_APPAREL' => [
            'electronics-and-gadgets' => [
                'tee shirt', 'tee shirts',
                'tank top', 'tank tops',
                'hoodie', 'hoodies',
                'sweatshirt', 'sweatshirts',
                'sweater', 'sweaters',
                'cardigan', 'cardigans',
                'jacket', 'jackets',
                'coat', 'coats',
                'leggings',
                'pajamas', 'pyjamas',
                'underwear', 'lingerie',
                'swimwear', 'swimsuit', 'swimsuits',
                'bikini', 'bikinis',
                'romper', 'rompers',
                'jumpsuit', 'jumpsuits',
                'socks', 'tights', 'blazer', 'blazers',
            ],
        ],
    ],
];
