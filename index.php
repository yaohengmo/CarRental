<?php


session_start();

// enable on-demand class loader
require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// create a log channel
$log = new Logger('main');
$log->pushHandler(new StreamHandler('logs/everything.log', Logger::DEBUG));
$log->pushHandler(new StreamHandler('logs/errors.log', Logger::ERROR));

//DB::$host = 'ipd8.info';
if ($_SERVER['SERVER_NAME'] == 'localhost') {
DB::$dbName = 'carrental';
DB::$user = 'carrental';
DB::$password = 'DLGbPGKfpby5FW5s';
}
else { 
    //remote connection
DB::$dbName = 'cp4724_carrental';
DB::$user = 'cp4724_carrental';
DB::$password = 'DLGbPGKfpby5FW5s';
}
DB::$encoding = 'utf8'; // defaults to latin1 if omitted
DB::$error_handler = 'sql_error_handler';
DB::$nonsql_error_handler = 'nonsql_error_handler';

function nonsql_error_handler($params) {
    global $app, $log;
    $log->error("Database error: " . $params['error']);
    http_response_code(500);
    $app->render('error_internal.html.twig');
    die;
}

function sql_error_handler($params) {
    global $app, $log;
    $log->error("SQL error: " . $params['error']);
    $log->error(" in query: " . $params['query']);
    http_response_code(500);
    $app->render('error_internal.html.twig');
    die; // don't want to keep going if a query broke
}

// instantiate Slim - router in front controller (this file)
// Slim creation and setup
$app = new \Slim\Slim(array(
    'view' => new \Slim\Views\Twig()
        ));

$view = $app->view();
$view->parserOptions = array(
    'debug' => true,
    'cache' => dirname(__FILE__) . '/cache'
);
$view->setTemplatesDirectory(dirname(__FILE__) . '/templates');

if (!isset($_SESSION['user'])) {
    $_SESSION['user'] = array();
}
$twig = $app->view()->getEnvironment();

$twig->addGlobal('user', $_SESSION['user']);

$app->get('/', function() use ($app) {    
    $app->render('index.html.twig',
            array('sessionUser' => $_SESSION['user']));
});
$app->get('/emailexists/:email', function($email) use ($app, $log){
    $user = DB::queryFirstRow("SELECT ID from customer WHERE email = %s", $email);
    if ($user){
        echo " Email already registered";
    }   
});

// State 1: first show
$app->get('/register', function() use ($app, $log) {
    $app->render('register.html.twig');
});

// State 2: submission
$app->post('/register', function() use ($app, $log) {
    $name = $app->request->post('name');
    $email = $app->request->post('email');
    $pass1 = $app->request->post('pass1');
    $pass2 = $app->request->post('pass2');
    $valueList = array ('name' => $name, 'email' => $email);
    // submission received - verify
    $errorList = array();
    if (strlen($name) < 3) {
        array_push($errorList, "Name must be at least 3 characters long");
        unset($valueList['name']);
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === FALSE) {
        array_push($errorList, "Email does not look like a valid email");
        unset($valueList['email']);
    } else {
        $user = DB::queryFirstRow("SELECT ID FROM customers WHERE email=%s", $email);        
        if ($user) {
            array_push($errorList, "Email already registered");
            unset($valueList['email']);
        }
    }
    if (!preg_match('/[0-9;\'".,<>`~|!@#$%^&*()_+=-]/', $pass1) || (!preg_match('/[a-z]/', $pass1)) || (!preg_match('/[A-Z]/', $pass1)) || (strlen($pass1) < 8)) {
        array_push($errorList, "Password must be at least 8 characters " .
                "long, contain at least one upper case, one lower case, " .
                " one digit or special character");
    } else if ($pass1 != $pass2) {
        array_push($errorList, "Passwords don't match");
    }
    //
    if ($errorList) {
        // STATE 3: submission failed        
        $app->render('register.html.twig', array(
            'errorList' => $errorList, 'v' => $valueList
        ));
    } else {
        // STATE 2: submission successful
        DB::insert('customers', array(
            'name' => $name, 'email' => $email,
            //'password' => $pass1
            'password' => password_hash($pass1, CRYPT_BLOWFISH)
        ));
        $id = DB::insertId();
        $log->debug(sprintf("User %s created", $id));
        $app->render('/register_success.html.twig');
    }
});
$app->get('/login', function() use ($app, $log) {
    $app->render('/login.html.twig');
});
$app->post('/login', function() use ($app, $log) {
    $email = $app->request->post('email');
    $pass = $app->request->post('pass');
    $user = DB::queryFirstRow("SELECT * FROM customers WHERE email=%s", $email);  
    //$userStaff = DB::queryFirstRow("SELECT * FROM staff WHERE name=%s", $name);    
    if (!$user) {
        $log->debug(sprintf("User failed for email %s from IP %s",
                    $email, $_SERVER['REMOTE_ADDR']));
        $app->render('/login.html.twig', array('loginFailed' => TRUE));
    } 
    
    else {
        // password MUST be compared in PHP because SQL is case-insenstive
        //if ($user['password'] == hash('sha256', $pass)) {
        if (password_verify($pass, $user['password'])) {
            // LOGIN successful
            unset($user['password']);
            $_SESSION['user'] = $user;
            $log->debug(sprintf("User %s logged in successfuly from IP %s",
                    $user['ID'], $_SERVER['REMOTE_ADDR']));
            $app->render('login_success.html.twig');
        } else {
            $log->debug(sprintf("User failed for email %s from IP %s",
                    $email, $_SERVER['REMOTE_ADDR']));
            $app->render('login.html.twig', array('loginFailed' => TRUE));            
        }
    }         
           
});

$app->get('/reservation', function() use ($app, $log) {
    $app->render('reservation.html.twig');
});

// State 2: submission
$app->get('/reserve2', function() use ($app, $log) {
    $productList = DB::query("SELECT * FROM cars");
    $app->render('reserve2.html.twig', array(
        'productList' => $productList
    ));
});
/*
$app->post('/reservation', function() use ($app, $log) {
    $carType = $app->request->post('carType');
    $pickupDate = $app->request->post('pickupDate');
    $returnDate = $app->request->post('returnDate');
    $location = $app->request->post('location');
    $rate = $app->request->post('rate');
    $valueList = array ( 'pickupDate' => $pickupDate,'returnDate' => $returnDate,
        'location' => $location, 'rate' => $rate);
    // submission received - verify
    $errorList = array();
    
    DB::queryFirstRow("SELECT car.ID, location FROM car")
    
      $user = DB::queryFirstRow("SELECT ID FROM reservations WHERE carType=%s", $carType);        
        if ($user) {
            array_push($errorList, "Car type already booked");
            unset($valueList['carType']);
        }
    //
    if ($errorList) {
        // STATE 3: submission failed        
        $app->render('/reservation.html.twig', array(
            'errorList' => $errorList, 'v' => $valueList
        ));
    } else {
        // STATE 2: submission successful
        DB::insert('reservations', array(
            'carID' => $carID, 'pickupDate' => $pickupDate, 'returnDate' => $returnDate
        ));
        $id = DB::insertId();
        $log->debug(sprintf("User %s created", $id));
        $app->render('/reservation_success.html.twig');
    }
});
 * 
 */
$app->get('/reservation_success', function() use ($app, $log) {
    $app->render('reservation_success.html.twig');
});

        
$app->get('/logout', function() use ($app, $log) {
    $_SESSION['user'] = array();
    $app->render('/logout_success.html.twig');
});

$app->run();