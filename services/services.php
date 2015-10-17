<?php

return array(
    'services' => array(
        'Event' => function () {
            if (class_exists('\App\Event\Library\Event')) {
                return new \App\Event\Library\Event();
            } else {
                return new \Nails\Event\Library\Event();
            }
        }
    )
);
