<?php namespace ITC\Core;
use ITC\Models\Agency;
use ITC\Models\Booking;
use ITC\Models\Contact;
use ITC\Models\Newsletter;
use ITC\Models\Pdf;
use ITC\Models\Search;
use ITC\Models\Vehicle;

class Requests {
	static public function initialize() {
    static::_register( [ 'showResult', 'showResults' ], [ 'book', 'contact', 'estimate', 'getAgencies', 'getResult', 'getResults', 'subscribeToNewsletter', 'recupCodePromo' ] );
    add_action( 'itc_send_cron_email', [ 'ITC\Core\Requests', 'onItcSendCronEmail' ] );
    //add_action( 'wp_ajax_recupCodePromo', 'recupCodePromo' ); // samuel etheve
		//add_action( 'wp_ajax_nopriv_recupCodePromo', 'recupCodePromo' ); //samuel etheve
	}
   //samuel 15/11/2017 modif function request pour code promo
  //Samuel Etheve - 15/11/2017 methode cree pour code promo
  static public function onWpAjaxRecupCodePromo() {

	//Nouvelle methode search - par hemraj 11/20/2017
    check_ajax_referer( 'recupCodePromo', 'security' );
	$search = new search($_POST['data']);
	$newSearch = $search->setNewSearch($_POST['data']);
	//print_r($newSearch);
    echo json_encode($newSearch);
  	exit;
	}


  static public function onAdminPostShowResult() {
    check_ajax_referer( 'showResult' );
    Search::update( ( object ) $_POST );
    wp_safe_redirect( Vehicle::getResultUrl( $_POST[ 'slug' ], $_POST[ 'lang' ] ) );
    exit();
	}

	static public function onAdminPostShowResults() {
    check_ajax_referer( 'showResults' );
    Search::update( ( object ) $_POST );
    $page = get_page_by_path( 'resultats' );
    wp_safe_redirect( get_page_link( pll_get_post( $page->ID, $_POST[ 'lang' ] ) ) );
    exit();
	}

  static public function onItcSendCronEmail( $email ) {
    wp_mail( $email->to, $email->subject, $email->message, $email->headers );
  }

  static public function onWpAjaxBook() {
    check_ajax_referer( 'book', 'security' );
    if ( is_null( ( $contact = Contact::getBySlug( 'notre-societe' ) ) ) ) return wp_send_json( false );

	//Ajout code promo par hemraj 10/11/2017
	$newSearch = new search($_POST['data']);
	$search = $newSearch->setNewSearch($_POST['data']);

    if ( is_null( ( $result = Vehicle::getResult( $_POST[ 'slug' ], $search->from, $search->to ) ) ) ) return wp_send_json( false );

    $booking = new Booking( $_POST[ 'estimate' ], $search, $result );
    $booking->update( wp_unslash( $_POST[ 'data' ] ) );
    if ( is_null( ( $number = Vehicle::book( $booking ) ) ) ) return wp_send_json( false );
    $booking->number = $number;
    $booking->save();

    $pdf = Pdf::create( $booking );

    $emailClient = ( object ) [
      'attachments' => [ $pdf ],
      'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
      'message' => pods_view( 'templates/email/bookingClient.php', null, false, 'cache', true ),
      'subject' => 'ITC TROPICAR - ' . __( 'Votre réservation' ,'itc' ),
      'to' => $booking->userEmail,
    ];
    wp_mail( $emailClient->to, $emailClient->subject, $emailClient->message, $emailClient->headers, $emailClient->attachments );

    $emailITC = ( object ) [
      'attachments' => [ $pdf ],
      'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
      'message' => pods_view( 'templates/email/bookingITC.php', compact( 'booking' ), false, 'cache', true ),
      'subject' => 'ITC TROPICAR - Réservation ' . $booking->number,
      'to' => $contact->bookingEmails,
    ];
    wp_mail( $emailITC->to, $emailITC->subject, $emailITC->message, $emailITC->headers, $emailITC->attachments );

    //ethsam 21-12-17 Intégration paiement SP-PLUS
    $recupMonthAndYear = explode("/", $_POST['data']['cardExpiration'], 2);

    //ethsam 04-01-2018 code promo et paiement
		$codePromoPaiementRecu = strtoupper($booking->search->codePromo);
		$codePromoPaiementRecu = !empty($codePromoPaiementRecu) ? $codePromoPaiementRecu : 'faux'; //samuel etheve 25-10-2017

		//ethsam 04-01-2018 defini les codes promo
		$codePromoPaiement1 = "PETITFUTE2018";
		$codePromoPaiement2 = "PROMO2018";
		$codePromoPaiement3 = "TESTPROMO";
		$codePromoPaiement4 = "ROUTARD2018";

		$prixdebasepaiement = $booking->priceResultPrice();
		$prixcalculpaiement = ($prixdebasepaiement - (($prixdebasepaiement * 10)/100));
		$prixcalculpaiement = floatval($prixcalculpaiement);
		$prixtotalttcpaiement = $booking->priceTotal();
		$prixcalcultotalpaiement = ($prixtotalttcpaiement - $prixdebasepaiement) + $prixcalculpaiement;
		$prixcalcultotalpaiement = number_format($prixcalcultotalpaiement, 2, ',', ' ');

  if ( ($codePromoPaiementRecu == $codePromoPaiement1) || ($codePromoPaiementRecu == $codePromoPaiement2) || ($codePromoPaiementRecu == $codePromoPaiement3) || ($codePromoPaiementRecu == $codePromoPaiement4) ) {
			$prixAPayer = $prixcalcultotalpaiement;
	} else {
			$prixAPayer = $booking->priceTotal();
	}

    $emailCB = ( object ) [
        'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
        'message' => 'vads_card_number = '.$booking->cardNumber.'<br />vads_cvv = '.$booking->cardCryptogram.'<br />vads_expiry_month = '.$recupMonthAndYear[0].'<br />vads_expiry_annee = '.$recupMonthAndYear[1].'<br />vads_payment_cards = '.$booking->cardMode.'<br />vads_trans_id = '.$booking->number.'<br />vads_cust_full_name = ' .$booking->userName.'<br />vads_cust_last_name = '. $booking->userSurname.'<br />vads_cust_phone = '.$booking->userPhone.'<br /> Code promo : '.$booking->search->codePromo.'<br /> Prix : '.$prixAPayer,
        'subject' => 'TEST PAIEMENT ITC TROPICAR',
        'to' => 'samuel.etheve@regie.re',
    ];
    wp_mail( $emailCB->to, $emailCB->subject, $emailCB->message, $emailCB->headers );

    wp_send_json( true );
    wp_send_json($emailITC->message);
  }


  static public function onWpAjaxContact() {
    check_ajax_referer( 'contact', 'security' );

    $contact = Contact::getBySlug( 'notre-societe' );

    $data = ( object ) wp_unslash( $_POST[ 'data' ] );
    $emailClient = ( object ) [
      'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
      'message' => pods_view( 'templates/email/contact.php', compact( 'data' ), false, 'cache', true ),
      'subject' => 'ITC TROPICAR - ' . $data->subject,
      'to' => $contact->email,
    ];
    $result = wp_mail( $emailClient->to, $emailClient->subject, $emailClient->message, $emailClient->headers );

    wp_send_json( $result );
  }

  static public function onWpAjaxEstimate() {
    check_ajax_referer( 'estimate', 'security' );
    if ( is_null( ( $contact = Contact::getBySlug( 'notre-societe' ) ) ) ) return wp_send_json( false );
    $newSearch = new search($_POST['data']);
	$search = $newSearch->setNewSearch($_POST['data']);
    if ( is_null( ( $result = Vehicle::getResult( $_POST[ 'slug' ], $search->from, $search->to ) ) ) ) return wp_send_json( false );

    $booking = new Booking( $_POST[ 'estimate' ], $search, $result );
    $booking->update( wp_unslash( $_POST[ 'data' ] ) );
    $booking->save();

    $pdf = Pdf::create( $booking );

    $emailClient = ( object ) [
      'attachments' => [ $pdf ],
      'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
      'message' => pods_view( 'templates/email/estimateClient.php', [ 'link' => Vehicle::getResultUrl( $_POST[ 'slug' ], $_POST[ 'lang' ] ) . '?estimate=' . $booking->id ], false, 'cache', true ),
      'subject' => 'ITC TROPICAR - ' . __( 'Votre demande de devis' ,'itc' ),
      'to' => $booking->userEmail,
    ];
    wp_mail( $emailClient->to, $emailClient->subject, $emailClient->message, $emailClient->headers, $emailClient->attachments );

    $emailITC = ( object ) [
      'attachments' => [ $pdf ],
      'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
      'message' => pods_view( 'templates/email/estimateITC.php', compact( 'booking' ), false, 'cache', true ),
      'subject' => 'ITC TROPICAR - Demande de devis',
      'to' => $contact->bookingEmails,
    ];
    wp_mail( $emailITC->to, $emailITC->subject, $emailITC->message, $emailITC->headers, $emailITC->attachments );

    $emailCron = ( object ) [
      'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
      'message' => pods_view( 'templates/email/estimateCron.php', [ 'link' => Vehicle::getResultUrl( $_POST[ 'slug' ], $_POST[ 'lang' ] ) . '?estimate=' . $booking->id ], false, 'cache', true ),
      'subject' => 'ITC TROPICAR - ' . __( 'Nous vous remercions pour votre visite !' ,'itc' ),
      'to' => $booking->userEmail,
    ];
    wp_schedule_single_event( time() + 7200, 'itc_send_cron_email', [ $emailCron ] );

    wp_send_json( true );
  }

  static public function onWpAjaxGetAgencies() {
    check_ajax_referer( 'getAgencies', 'security' );
    $result = Agency::get();
    wp_send_json( $result );
  }

  static public function onWpAjaxGetResult() {
    check_ajax_referer( 'getResult', 'security' );

    if ( !empty( $_POST[ 'estimate' ] ) ) {
      $pod = pods( 'itc_estimate', $_POST[ 'estimate' ] );
      if ( $pod->exists() ) {
        if ( is_null( ( $i = Vehicle::getBySlug( $_POST[ 'slug' ] ) ) ) ) return wp_send_json( false );
        $fromDateTime = explode( ' ', $pod->field( 'fromdate' ) );
        $toDateTime = explode( ' ', $pod->field( 'todate' ) );
        $hasChanged = Search::update( ( object ) [ 'fromDate' => $fromDateTime[ 0 ], 'fromTime' => $fromDateTime[ 1 ], 'toDate' => $toDateTime[ 0 ], 'toTime' => $toDateTime[ 1 ], 'type' => $i->type ] );
        if ( $hasChanged ) return wp_send_json( true );
      }
    }
    $search = Search::getLast();
    if ( is_null( ( $result = Vehicle::getResult( $_POST[ 'slug' ], $search->from, $search->to ) ) ) ) return wp_send_json( false );
    $booking = new Booking( $_POST[ 'estimate' ], $search, $result );
    wp_send_json( [ 'booking' => $booking, 'options' => get_option( 'itcOptions' ) ] );
  }

  static public function onWpAjaxGetResults() {
    check_ajax_referer( 'getResults', 'security' );
    $search = Search::getLast();
    $results = Vehicle::getResults( $search->type, $search->from, $search->to );
    foreach ( $results as $item ) pods_view( 'templates/results/item.php', compact( 'item' ) );
    exit();
  }

  static public function onWpAjaxSubscribeToNewsletter() {
    check_ajax_referer( 'subscribeToNewsletter', 'security' );
    $result = Newsletter::addUser( wp_unslash( $_POST[ 'data' ] ) );
    wp_send_json( $result );
  }

  static protected function _register( $postActions, $ajaxActions ) {
    foreach( $ajaxActions as $action ) {
      add_action( 'wp_ajax_' . $action, [ 'ITC\Core\Requests', 'onWpAjax' . ucfirst( $action ) ] );
      add_action( 'wp_ajax_nopriv_' . $action, [ 'ITC\Core\Requests', 'onWpAjax' . ucfirst( $action ) ] );
    }
    foreach( $postActions as $action ) {
      add_action( 'admin_post_' . $action, [ 'ITC\Core\Requests', 'onAdminPost' . ucfirst( $action ) ] );
      add_action( 'admin_post_nopriv_' . $action, [ 'ITC\Core\Requests', 'onAdminPost' . ucfirst( $action ) ] );
    }
  }

}
