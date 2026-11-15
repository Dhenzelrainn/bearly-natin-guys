<?php

return [
    'refusal' => 'I’m Bearly’s shopping assistant. I can help with Bearly, shopping, and products listed in our marketplace, but not unrelated questions.',
    'not_found' => 'I couldn’t find a matching product in Bearly’s current listings. Try a product name, category, or store.',
    'uncertain' => 'I can’t confirm that from Bearly’s current information. Please contact Bearly Support for help.',
    'welcome' => 'Hi! I’m Bearly Assistant 👋 How can I help you today?',
    'knowledge' => [
        [
            'topic' => 'about_bearly',
            'keywords' => [
                'what is bearly', 'about bearly', 'bearly marketplace', 'what does bearly do',
                'how does bearly work', 'how bearly works',
                'bearly system', 'bearly platform', 'ano ang bearly', 'paano gumagana ang bearly',
            ],
            'facts' => 'Bearly is an e-commerce marketplace where buyers can discover products from participating stores, view product details, add items to a cart, and place orders through supported checkout.',
        ],
        [
            'topic' => 'buyer_registration',
            'keywords' => [
                'buyer registration', 'register as a buyer', 'create a buyer account', 'sign up',
                'create an account', 'email verification', 'verify my email', 'buyer account',
                'mag register', 'mag sign up',
            ],
            'facts' => 'New Buyer registration requires successful email verification. After the email proof succeeds, the Buyer account activates; a Buyer does not need a government-ID upload or Admin approval. Never ask for or share a password or one-time verification code in chat.',
        ],
        [
            'topic' => 'checkout_and_payment',
            'keywords' => [
                'checkout', 'payment', 'payment method', 'how do i pay', 'how to pay',
                'cash on delivery', 'cod', 'bayad', 'magbayad',
            ],
            'facts' => 'Bearly checkout currently supports Cash on Delivery (COD) only. Buyers should review the current cart and checkout page for the order total and available checkout action. Do not claim that Bearly supports cards, wallets, bank transfers, or other payment methods.',
        ],
        [
            'topic' => 'order_tracking',
            'keywords' => [
                'track an order', 'track my order', 'order tracking', 'order status',
                'delivery status', 'track my parcel', 'where is my order', 'saan na ang order',
                'nasaan na ang order', 'order ko',
            ],
            'facts' => 'Buyers can review their own order status from the Orders / Purchases area after signing in. This assistant does not look up personal orders or provide live tracking details. Orders page: /profile#purchases.',
        ],
        [
            'topic' => 'returns_and_refunds',
            'keywords' => [
                'return', 'returns', 'refund', 'refunds', 'return request', 'refund request',
                'return an item', 'ibalik ang item', 'ibalik ito',
            ],
            'facts' => 'Buyers can request a return or refund from eligible completed Seller Orders within the system’s three-calendar-day request window. Eligibility, review status, and any approved physical-return instructions are shown in the Buyer account. The assistant cannot approve a return or promise a refund.',
        ],
        [
            'topic' => 'contact_support',
            'keywords' => [
                'contact support', 'contact bearly', 'bearly support', 'support ticket',
                'talk to support', 'human support', 'need help', 'contact us', 'kailangan ko ng tulong',
            ],
            'facts' => 'For support that needs a person to review details, use Bearly’s Contact page to submit a support ticket. The current support flow is a ticket, not live-agent chat.',
        ],
    ],
];
