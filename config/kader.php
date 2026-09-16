<?php

return [
    /*
     * Days with no roll call expected (Carbon day numbers: 0 = Sunday … 6 = Saturday).
     * Default is the Friday–Saturday weekend; schools on a Saturday–Sunday week
     * set KADER_WEEKEND=6,0.
     */
    'weekend' => array_map('intval', array_filter(explode(',', env('KADER_WEEKEND', '5,6')), 'strlen')),
];
