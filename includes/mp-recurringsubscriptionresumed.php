<?php
/*
 * Hooks into Uncanny Automator to create a trigger for MemberPress
 * Trigger: Recurring Membership is resumed
 * */

# Hook into Uncanny Automator triggers
add_action('uncanny_automator_add_integration_triggers_actions_tokens', 'uncanny_automator_triggers_mepr_subscription_resumed');

#Push the trigger into the Automator Object
function uncanny_automator_triggers_mepr_subscription_resumed()
{
    global $uncanny_automator;

    $trigger = array(

        'author' => 'Bliksem LLC',
        'support_link' => 'https://github.com/andrenellin',
        'integration' => 'MP',
        'code' => 'RESUMEPRODUCTRECURRING',
        /* translators: Logged-in trigger - MemberPress */
        'sentence' => sprintf(esc_attr__('A user resumes {{a recurring subscription product:%1$s}}', 'uncanny-automator'), 'MPPRODUCT'),
        /* translators: Logged-in trigger - MemberPress */
        'select_option_name' => esc_attr__('A user resumes {{a recurring subscription product}}', 'uncanny-automator'),
        'action' => 'mepr-event-subscription-resumed',
        'priority' => 20,
        'accepted_args' => 1,
        'validation_function' => 'mp_product_resumed',
        'options' => [
            $uncanny_automator->helpers->recipe->memberpress->options->all_memberpress_products_recurring(null, 'MPPRODUCT', ['uo_include_any' => true]),
        ],
    );

    # Register the Trigger
    $uncanny_automator->register_trigger($trigger);

    return;
}

/**
 * @param \MeprEvent $event
 */
function mp_product_resumed(\MeprEvent $event)
{
    global $uncanny_automator;

    /** @var \MeprTransaction $transaction */
    // ### $transaction contains all subscription details
    $transaction = $event->get_data();

    // echo '<pre>';
    // echo '<h3>Check Output $transaction</h3>';
    // print_r($transaction);
    // echo '</pre>';
    // bliksem_custom_logs($array_value);

    /** @var \MeprProduct $product */
    // ### obtain product id from $transaction
    $product = $transaction->product();
    $product_id = $product->ID; //
    $user_id = absint($transaction->user()->ID);
    if ('lifetime' === (string) $product->period_type) {
        return;
    }

    // Fetches available recipes for the trigger
    $recipes = $uncanny_automator->get->recipes_from_trigger_code('RESUMEPRODUCTRECURRING');
    if (empty($recipes)) {
        return;
    }

    // Fetches meta for recipes
    $required_product = $uncanny_automator->get->meta_from_recipes($recipes, 'MPPRODUCT');

    $matched_recipe_ids = array();
    //Add where option is set to Any product
    foreach ($recipes as $recipe_id => $recipe) {
        foreach ($recipe['triggers'] as $trigger) {
            $trigger_id = $trigger['ID']; //return early for all products
            if (absint($required_product[$recipe_id][$trigger_id]) === $product_id || intval('-1') === intval($required_product[$recipe_id][$trigger_id])) {
                $matched_recipe_ids[] = [
                    'recipe_id' => $recipe_id,
                    'trigger_id' => $trigger_id,
                ];
            }
        }
    }
    if (empty($matched_recipe_ids)) {
        return;
    }
    foreach ($matched_recipe_ids as $matched_recipe_id) {
        $recipe_args = [
            'code' => 'RESUMEPRODUCTRECURRING',
            'meta' => 'MPPRODUCT',
            'user_id' => $user_id,
            'recipe_to_match' => $matched_recipe_id['recipe_id'],
            'trigger_to_match' => $matched_recipe_id['trigger_id'],
            'ignore_post_id' => true,
            'is_signed_in' => true,
        ];

        $results = $uncanny_automator->maybe_add_trigger_entry($recipe_args, false);
        if (empty($results)) {
            continue;
        }
        foreach ($results as $result) {
            if (true === $result['result']) {
                $trigger_meta = [
                    'user_id' => $user_id,
                    'trigger_id' => $result['args']['trigger_id'],
                    'trigger_log_id' => $result['args']['get_trigger_id'],
                    'run_number' => $result['args']['run_number'],
                ];

                $trigger_meta['meta_key'] = 'MPPRODUCT';
                $trigger_meta['meta_value'] = $product_id;
                $uncanny_automator->insert_trigger_meta($trigger_meta);
                update_user_meta($user_id, 'MPPRODUCT', $product_id);

                $uncanny_automator->maybe_trigger_complete($result['args']);
            }
        }
    }
}