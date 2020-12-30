<?php

use PagHiper\PagHiper;

// Include SDK for our call
require_once WC_Paghiper::get_plugin_path() . 'includes/paghiper-php-sdk/vendor/autoload.php';

$wp_api_url = add_query_arg( 'wc-api', 'WC_Gateway_Paghiper', home_url( '/' ) );
add_action( 'woocommerce_api_wc_gateway_paghiper', 'woocommerce_boleto_paghiper_check_ipn_response' );

function woocommerce_paghiper_valid_ipn_request($return, $order_no, $settings) {

    $order          = new WC_Order($order_no);
    $order_status   = $order->get_status();

    // Trata os retornos

    // Primeiro checa se o pedido ja foi pago.
    $statuses = ((strpos($order_status, 'wc-') === FALSE) ? array('processing', 'completed') : array('wc-processing', 'wc-completed'));
    $already_paid = (in_array( $order_status, $statuses )) ? true : false;

    if($already_paid) {
        // Se sim, os próximos Status só podem ser Completo, Disputa ou Estornado
        switch ( $return['status'] ) {
            case "completed" :
                $order->add_order_note( __( 'PagHiper: Pagamento completo. O valor ja se encontra disponível para saque.' , 'woo_paghiper' ) );
                break;
            case "processing" :
                $order->update_status( 'on-hold', __( 'PagHiper: Pagamento em disputa. Para responder, faça login na sua conta Paghiper e procure pelo número da transação.', 'woo_paghiper' ) );
                increase_order_stock( $order, $settings );
                break;
        }

    } else {

        // Se não, os status podem ser Cancelado, Aguardando ou Aprovado
        switch ( $return['status'] ) {
            case "pending" :

                if($order_status !== ((strpos($order_status, 'wc-') === FALSE) ? 'on-hold' : 'wc-on-hold')) {
                    $waiting_status = (!empty($settings['set_status_when_waiting'])) ? $settings['set_status_when_waiting'] : 'on-hold';
                    $order->update_status( $waiting_status, __( 'Boleto PagHiper: Novo boleto gerado. Aguardando compensação.', 'woo_paghiper' ) );
                } else {
                    $order->add_order_note( __( 'PagHiper: Post de notificação recebido. Aguardando compensação do boleto.' , 'woo_paghiper' ) );
                }
                break;
            case "reserved" :

                $order->add_order_note( __( 'PagHiper: Pagamento pré-compensado (reservado). Aguarde confirmação.' , 'woo_paghiper' ) );
                break;
            case "canceled" :

                    // TODO: Checar se data do boleto cancelado é menor que a atual (do pedido)
                    // Se data do pedido for maior que a do boleto cancelado, não cancelar pedido

                    $cancelled_status = (!empty($settings['set_status_when_cancelled'])) ? $settings['set_status_when_cancelled'] : 'cancelled';
                    
                    $order->update_status( $cancelled_status, __( 'PagHiper: Boleto Cancelado.', 'woo_paghiper' ) );
                    increase_order_stock( $order, $settings );
                break;
            case "paid" :

                // For WooCommerce 2.2 or later.
                add_post_meta( $order_no, '_transaction_id', (string) $return['transaction_id'], true );

                // Changing the order for processing and reduces the stock.
                $order->payment_complete();

                if(strpos('paid', $settings['set_status_when_paid']) === FALSE) {
                    $order->update_status( $settings['set_status_when_paid'], __( 'PagHiper: Boleto Pago.', 'woo_paghiper' ) );
                } else {
                    $order->add_order_note( __( 'PagHiper: Pagamento compensado.', 'woo_paghiper' ) );
                }

                break;
            case "refunded" :
                $order->update_status( 'refunded', __( 'PagHiper: Pagamento estornado. O valor foi ja devolvido ao cliente. Para mais informações, entre em contato com a equipe de atendimento Paghiper.' , 'woo_paghiper' ) );
                break;
        }
    }
}

function woocommerce_boleto_paghiper_check_ipn_response() {

    $transaction_type = (isset($_GET) && array_key_exists('gateway', $_GET)) ? sanitize_text_field($_GET['gateway']) : 'billet';
    $settings = ($is_pix) ? get_option( 'woocommerce_paghiper_pix_settings' ) : get_option( 'woocommerce_paghiper_billet_settings' );
    $log = wc_paghiper_initialize_log( $settings[ 'debug' ] );

    $token 			= $settings['token'];
    $api_key 		= $settings['api_key'];

    $PagHiperAPI 	= new PagHiper($api_key, $token);
    $response 		= $PagHiperAPI->transaction()->process_ipn_notification($_POST['notification_id'], $_POST['transaction_id'], $transaction_type);

    if($response['result'] == 'success') {

        if ( $log ) {
            wc_paghiper_add_log( $log, sprintf('Pedido #%s: Post de retorno da PagHiper confirmado.', $response['order_id']) );
        }


        // Print a 200 HTTP code for the notification engine
        header( 'HTTP/1.1 200 OK' );

        // Carry on with the operation
        woocommerce_paghiper_valid_ipn_request( $response, $response['order_id'], $settings );


    } else {

        if ( $log ) {
            $error = $response->get_error_message();
            wc_paghiper_add_log( $log, sprintf( 'Erro: não foi possível checar o post de retorno da PagHiper. Mensagem: %s', $response ) );
        }

        wp_die( esc_html__( 'Solicitação PagHiper Não Autorizada', 'woo_paghiper' ), esc_html__( 'Solicitação PagHiper Não Autorizada', 'woo_paghiper' ), array( 'response' => 401 ) );
    }

} 


/**
 * Increase order stock.
 *
 * @param int $order_id Order ID.
 */
function increase_order_stock( $order, $settings ) {

    /* Changing setting keys from Woo-Boleto-Paghiper 1.2.6.1 */
    $replenish_stock = ($settings['replenish_stock'] !== '') ? $settings['replenish_stock'] : $settings['incrementar-estoque'];

    $order_id = $order->get_id();
    
    if ( 'yes' === get_option( 'woocommerce_manage_stock' ) && $replenish_stock == true && $order && 0 < count( $order->get_items() ) ) {
        if ( apply_filters( 'woocommerce_payment_complete_reduce_order_stock', $order && ! $order->get_data_store()->get_stock_reduced( $order_id ), $order_id ) ) {
            if ( function_exists( 'wc_maybe_increase_stock_levels' ) ) {
                wc_maybe_increase_stock_levels( $order_id );
            }
        }
    }
}