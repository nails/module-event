<?php

return [
    'services' => [
        'Event' => function () {
            if (class_exists('\App\Event\Service\Event')) {
                return new \App\Event\Service\Event();
            } else {
                return new \Nails\Event\Service\Event();
            }
        }
    ]
];
