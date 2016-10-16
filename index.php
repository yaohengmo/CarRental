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
    $productList = DB::query("SELECT * FROM cars");
    $app->render('index.html.twig', array(
        'productList' => $productList
    ));
});

$app->get('/cart', function() use ($app) {
    $cartitemList = DB::query(
                    "SELECT cartitems.ID as ID, productID, quantity,"
                    . " makeModel, description, imagePath, rate "
                    . " FROM cartitems, products "
                    . " WHERE cartitems.productID = cars.ID AND sessionID=%s", session_id());
    $app->render('cart.html.twig', array(
        'cartitemList' => $cartitemList
    ));
});

$app->post('/cart', function() use ($app) {
    $productID = $app->request()->post('productID');
    $quantity = $app->request()->post('quantity');
    // FIXME: make sure the item is not in the cart yet
    $item = DB::queryFirstRow("SELECT * FROM cartitems WHERE productID=%d AND sessionID=%s", $productID, session_id());
    if ($item) {
        DB::update('cartitems', array(
            'sessionID' => session_id(),
            'productID' => $productID,
            'quantity' => $item['quantity'] + $quantity
                ), "productID=%d AND sessionID=%s", $productID, session_id());
    } else {
        DB::insert('cartitems', array(
            'sessionID' => session_id(),
            'productID' => $productID,
            'quantity' => $quantity
        ));
    }
    // show current contents of the cart
    $cartitemList = DB::query(
                    "SELECT cartitems.ID as ID, productID, quantity,"
                    . " makeModel, description, imagePath, rate "
                    . " FROM cartitems, cars "
                    . " WHERE cartitems.productID = cars.ID AND sessionID=%s", session_id());
    $app->render('cart.html.twig', array(
        'cartitemList' => $cartitemList
    ));
});
// AJAX call, not used directy by user
$app->get('/cart/update/:cartitemID/:quantity', function($cartitemID, $quantity) use ($app) {
    if ($quantity == 0) {
        DB::delete('cartitems', 'cartitems.ID=%d AND cartitems.sessionID=%s', $cartitemID, session_id());
    } else {
        DB::update('cartitems', array('quantity' => $quantity), 'cartitems.ID=%d AND cartitems.sessionID=%s', $cartitemID, session_id());
    }
    echo json_encode(DB::affectedRows() == 1);
});

// order handling
$app->map('/order', function () use ($app) {
    $totalBeforeTax = DB::queryFirstField(
                    "SELECT SUM(cars.rate * cartitems.quantity) "
                    . " FROM cartitems, cars "
                    . " WHERE cartitems.sessionID=%s AND cartitems.productID=cars.ID", session_id());
    // TODO: properly compute taxes, shipping, ...
    
    $taxes = ($totalBeforeTax * 0.15);
    $totalWithTaxes = $totalBeforeTax + $taxes;

    if ($app->request->isGet()) {
        $app->render('order.html.twig', array(
            'totalBeforeTax' => number_format($totalBeforeTax, 2),
            'taxes' => number_format($taxes, 2),
            'totalWithTaxes' => number_format($totalWithTaxes, 2)
        ));
    } else {
        $name = $app->request->post('name');
        $email = $app->request->post('email');
        $address = $app->request->post('address');
        $postalCode = $app->request->post('postalCode');
        $phoneNumber = $app->request->post('phoneNumber');
        $valueList = array(
            'name' => $name,
            'email' => $email,
            'address' => $address,
            'postalCode' => $postalCode,
            'phoneNumber' => $phoneNumber
        );
        // FIXME: verify inputs - MUST DO IT IN A REAL SYSTEM
        $errorList = array();
        //
        if ($errorList) {
            $app->render('order.html.twig', array(
                'totalBeforeTax' => number_format($totalBeforeTax, 2),
                'taxes' => number_format($taxes, 2),
                'totalWithTaxes' => number_format($totalWithTaxes, 2),
                'v' => $valueList
            ));
        } else { // SUCCESSFUL SUBMISSION
            DB::$error_handler = FALSE;
            DB::$throw_exception_on_error = TRUE;
            // PLACE THE ORDER
            try {
                DB::startTransaction();
                // 1. create summary record in 'orders' table (insert)
                DB::insert('orders', array(
                    'customerID' => $_SESSION['user'] ? $_SESSION['user']['ID'] : NULL,
                    'name' => $name,
                    'address' => $address,
                    'postalCode' => $postalCode,
                    'email' => $email,
                    'phoneNumber' => $phoneNumber,
                    'totalBeforeTax' => $totalBeforeTax,
                    'taxes' => $taxes,
                    'totalWithTaxes' => $totalWithTaxes,
                    'dateTimePlaced' => date('Y-m-d H:i:s')
                ));
                $orderID = DB::insertId();
                // 2. copy all records from cartitems to 'orderitems' (select & insert)
                $cartitemList = DB::query(
                                "SELECT productID as origProductID, quantity, rate"
                                . " FROM cartitems, cars "
                                . " WHERE cartitems.productID = cars.ID AND sessionID=%s", session_id());
                // add orderID to every sub-array (element) in $cartitemList
                array_walk($cartitemList, function(&$item, $key) use ($orderID) {
                    $item['orderID'] = $orderID;
                });
                /* This is the same as the following foreach loop:
                  foreach ($cartitemList as &$item) {
                  $item['orderID'] = $orderID;
                  } */
                DB::insert('orderitems', $cartitemList);
                // 3. delete cartitems for this user's session (delete)
                DB::delete('cartitems', "sessionID=%s", session_id());
                DB::commit();
                // TODO: send a confirmation email
                /*
                  $emailHtml = $app->view()->getEnvironment()->render('email_order.html.twig');
                  $headers = "MIME-Version: 1.0\r\n";
                  $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                  mail($email, "Order " .$orderID . " placed ", $emailHtml, $headers);
                 */
                //
                $app->render('order_success.html.twig');
            } catch (MeekroDBException $e) {
                DB::rollback();
                sql_error_handler(array(
                    'error' => $e->getMessage(),
                    'query' => $e->getQuery()
                ));
            }
        }
    }
})->via('GET', 'POST');

// PASSWORD RESET

function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

$app->map('/passreset', function () use ($app, $log) {
    // Alternative to cron-scheduled cleanup
    if (rand(1,1000) == 111) {
        // TODO: do the cleanup 1 in 1000 accessed to /passreset URL
    }
    if ($app->request()->isGet()) {
        $app->render('passreset.html.twig');
    } else {
        $email = $app->request()->post('email');
        $user = DB::queryFirstRow("SELECT * FROM customers WHERE email=%s", $email);
        if ($user) {
            $app->render('passreset_success.html.twig');
            $secretToken = generateRandomString(50);
            // VERSION 1: delete and insert
            /*
              DB::delete('passresets', 'userID=%d', $user['ID']);
              DB::insert('passresets', array(
              'userID' => $user['ID'],
              'secretToken' => $secretToken,
              'expiryDateTime' => date("Y-m-d H:i:s", strtotime("+5 hours"))
              )); */
            // VERSION 2: insert-update TODO
            DB::insertUpdate('passresets', array(
                'userID' => $user['ID'],
                'secretToken' => $secretToken,
                'expiryDateTime' => date("Y-m-d H:i:s", strtotime("+5 minutes"))
            ));
            // email user
            $url = 'http://' . $_SERVER['SERVER_NAME'] . '/passreset/' . $secretToken;
            $html = $app->view()->render('email_passreset.html.twig', array(
                'name' => $user['name'],
                'url' => $url
            ));
            $headers = "MIME-Version: 1.0\r\n";
            $headers.= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers.= "From: Noreply <noreply@ipd8.info>\r\n";
            $headers.= "To: " . htmlentities($user['name']) . " <" . $email . ">\r\n";

            mail($email, "Password reset from ASFAR CarRental", $html, $headers);
        } else {
            $app->render('passreset.html.twig', array('error' => TRUE));
        }
    }
})->via('GET', 'POST');

$app->get('/scheduled/daily', function() use ($app, $log) {
    DB::$error_handler = FALSE;
    DB::$throw_exception_on_error = TRUE;
            // PLACE THE ORDER
    $log->debug("Daily scheduler run started");
    // 1. clean up old password reset requests
    try {
        DB::delete('passresets', "expiryDateTime < NOW()");    
        $log->debug("Password resets clean up, removed " . DB::affectedRows());
    } catch (MeekroDBException $e) {
        sql_error_handler(array(
                    'error' => $e->getMessage(),
                    'query' => $e->getQuery()
                ));
    }
    // 2. clean up old cart items (normally we never do!)
    try {
        DB::delete('cartitems', "createdTS < DATE(DATE_ADD(NOW(), INTERVAL -1 DAY))");
    } catch (MeekroDBException $e) {
        sql_error_handler(array(
                    'error' => $e->getMessage(),
                    'query' => $e->getQuery()
                ));
    }
    $log->debug("Cart items clean up, removed " . DB::affectedRows());
    $log->debug("Daily scheduler run completed");
    echo "Completed";
});

function verifyPassword($pass1) {
    if (!preg_match('/[0-9;\'".,<>`~|!@#$%^&*()_+=-]/', $pass1) || (!preg_match('/[a-z]/', $pass1)) || (!preg_match('/[A-Z]/', $pass1)) || (strlen($pass1) < 8)) {
        return "Password must be at least 8 characters " .
                "long, contain at least one upper case, one lower case, " .
                " one digit or special character";
    }
    return TRUE;
}

$app->map('/passreset/:secretToken', function($secretToken) use ($app) {
    $row = DB::queryFirstRow("SELECT * FROM passresets WHERE secretToken=%s", $secretToken);
    if (!$row) {
        $app->render('passreset_notfound_expired.html.twig');
        return;
    }
    if (strtotime($row['expiryDateTime']) < time()) {
        $app->render('passreset_notfound_expired.html.twig');
        return;
    }
    //
    if ($app->request()->isGet()) {
        $app->render('passreset_form.html.twig');
    } else {
        $pass1 = $app->request()->post('pass1');
        $pass2 = $app->request()->post('pass2');
        // TODO: verify password quality and that pass1 matches pass2
        $errorList = array();
        $msg = verifyPassword($pass1);
        if ($msg !== TRUE) {
            array_push($errorList, $msg);
        } else if ($pass1 != $pass2) {
            array_push($errorList, "Passwords don't match");
        }
        //
        if ($errorList) {
            $app->render('passreset_form.html.twig', array(
                'errorList' => $errorList
            ));
        } else {
            // success - reset the password
            DB::update('customers', array(
                'password' => password_hash($pass1, CRYPT_BLOWFISH)
                    ), "ID=%d", $row['userID']);
            DB::delete('passresets','secretToken=%s', $secretToken);
            $app->render('passreset_form_success.html.twig');
        }
    }
})->via('GET', 'POST');




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
            $app->render('/login_success.html.twig');
        } else {
            $log->debug(sprintf("User failed for email %s from IP %s",
                    $email, $_SERVER['REMOTE_ADDR']));
            $app->render('login.html.twig', array('loginFailed' => TRUE));            
        }
    }         
           
});



// State 2: submission
$app->get('/reservation', function() use ($app, $log) {
    $productList = DB::query("SELECT DISTINCT makeModel,imagePath,rate,description FROM cars");
    $app->render('/reservation.html.twig', array(
        'productList' => $productList
    ));
});


$app->get('/reservation_success', function() use ($app, $log) {
    $app->render('reservation_success.html.twig');
});

        
$app->get('/logout', function() use ($app, $log) {
    $_SESSION['user'] = array();
    $app->render('/logout_success.html.twig');
});

$app->run();