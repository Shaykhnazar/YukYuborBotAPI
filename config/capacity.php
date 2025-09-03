<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Distribution System Master Switch
    |--------------------------------------------------------------------------
    |
    | Controls whether the distribution system is enabled. When disabled,
    | the system falls back to basic matching without capacity limits,
    | round-robin distribution, or automatic rebalancing.
    |
    */
    'enabled' => env('DISTRIBUTION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Deliverer Capacity Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration controls the capacity limits for deliverers in the
    | matching system. These values determine how many active responses
    | a deliverer can handle simultaneously.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Maximum Deliverer Capacity
    |--------------------------------------------------------------------------
    |
    | The maximum number of pending/partial responses a deliverer can have
    | at any given time. Once this limit is reached, no new matches will
    | be sent to the deliverer until some responses are resolved.
    |
    */
    'max_deliverer_capacity' => env('DELIVERER_MAX_CAPACITY', 1),

    /*
    |--------------------------------------------------------------------------
    | Rebalancing Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the automatic rebalancing system that redistributes
    | excess responses when deliverers exceed their capacity limits.
    |
    */
    'rebalancing' => [
        
        /*
        | Enable automatic rebalancing when deliverers accept responses
        */
        'enabled' => env('REBALANCING_ENABLED', true),
        
        /*
        | Auto-reject responses if no alternative deliverers are available
        */
        'auto_reject_when_no_alternatives' => env('AUTO_REJECT_NO_ALTERNATIVES', true),
        
        /*
        | Maximum number of redistribution attempts per response
        */
        'max_redistribution_attempts' => env('MAX_REDISTRIBUTION_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Distribution Strategy
    |--------------------------------------------------------------------------
    |
    | Controls how new matches are distributed among available deliverers.
    | Options: 'round_robin', 'least_loaded', 'random'
    |
    */
    'distribution_strategy' => env('DISTRIBUTION_STRATEGY', 'round_robin'),

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Analytics
    |--------------------------------------------------------------------------
    |
    | Settings for capacity monitoring and system analytics.
    |
    */
    'monitoring' => [
        
        /*
        | Log capacity events for monitoring
        */
        'log_capacity_events' => env('LOG_CAPACITY_EVENTS', true),
        
        /*
        | Track capacity utilization metrics
        */
        'track_utilization_metrics' => env('TRACK_UTILIZATION_METRICS', true),
    ],

];