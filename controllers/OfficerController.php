<?php

namespace app\controllers;

use app\core\App;
use app\core\Controller;
use app\core\db\DbModel;
use app\core\middlewares\ActiveMiddleware;
use app\core\middlewares\OfficerMiddleware;
use app\core\Request;
use app\core\Response;
use app\core\Router;
use app\models\Category;
use app\models\Guideline;
use app\models\LoginForm;
use app\models\Notification;
use app\models\SubCategory;

class OfficerController extends Controller
{
    public string $layout = 'officer';

    public function __construct()
    {
        $this->registerMiddleware(new OfficerMiddleware());
        $this->registerMiddleware(new ActiveMiddleware());

    }

    public function index()
    {
        return $this->render('officer_index', []);
    }

    public function guidelines(Request $request, Response $response)
    {
        $guideline = new Guideline();

        $query = mb_split("&", parse_url($request->getRequestURI(), PHP_URL_QUERY));
        if (!empty($query)) foreach ($query as $qr) {
            $vars = mb_split('=', $qr);
            if ($vars[0] != null)
                $_GET[$vars[0]] = $vars[1];
        }

        if (isset($_GET['delete_id'])) {
            if (App::$app->session->get('VERIFIED') === 'TRUE') {
                App::$app->session->unset_key('VERIFIED');

                $guideline->update(['guid_id' => $_GET['delete_id']], ['guid_status' => '4']);
                Notification::addNotification(Guideline::getCategoryID($_GET['delete_id']), Notification::DELETE_NOTIFICATION, Notification::GUIDELINE);
                App::$app->response->redirect('/officer/guidelines');
                exit();

            }
            return $this->requireVerification($request);
        } elseif (isset($_GET['edit_id'])) {
            if ($request->method() === "post") {
                $request->setRequestUri("/officer/add-guideline?edit_id=" . $_GET['edit_id']);
                $request->setPath("/officer/add-guideline");
                App::$app->run();
                exit();
            }

            return $this->render('officer_add_guideline');

        } elseif (isset($_GET['draft_id'])) {
            if (App::$app->session->get('VERIFIED') === 'TRUE') {
                App::$app->session->unset_key('VERIFIED');

                $guideline = Guideline::findOne(['guid_id' => $_GET['draft_id']]);
                if ($guideline->getGuidStatus() === '2') {
                    $guideline->update(['guid_id' => $_GET['draft_id']], ['guid_status' => '0']);
                } else {
                    $guideline->update(['guid_id' => $_GET['draft_id']], ['guid_status' => '2']);
                }
                App::$app->response->redirect('/officer/guidelines');
                exit();
            }

            return $this->requireVerification($request);

        }

        return $this->render('officer_guidelines');
    }

    public function add_guideline(Request $request, Response $response)
    {

        if ($request->method() === 'post') {
            if (App::$app->session->get('VERIFIED') === 'TRUE') {
                App::$app->session->unset_key('VERIFIED');

                $guideline = new Guideline();

                $query = mb_split("&", parse_url($request->getRequestURI(), PHP_URL_QUERY));
                if (!empty($query)) foreach ($query as $qr) {
                    $vars = mb_split('=', $qr);
                    if ($vars[0] != null)
                        $_GET[$vars[0]] = $vars[1];
                }

                if (isset($_GET["edit_id"])) {
                    $data = $request->getBody();
                    if (!isset($data['guid_status'])) {
                        $data['guid_status'] = '0';
                    }
                    $guideline->update(['guid_id' => $_GET['edit_id']], $data);
                    Notification::addNotification(Guideline::getCategoryID($_GET['edit_id']), Notification::UPDATE_NOTIFICATION, Notification::GUIDELINE);
                    App::$app->response->redirect('/officer/guidelines');
                    exit();
                }

                $guideline->loadData($request->getBody());
                if ($guideline->save()) {
                    Notification::addNotification(Guideline::getCategoryID(DbModel::lastInsertID()), Notification::CREATE_NOTIFICATION, Notification::GUIDELINE);
                    App::$app->response->redirect('/officer/guidelines');
                    exit();
                } else {
                    echo '<script>alert("Fail to save the guideline")</script>';
                }
            }
            return $this->requireVerification($request);
        }
        return $this->render('officer_add_guideline');


    }

    public function categories(Request $request, Response $response)
    {
        $category = new Category();
        $mode = '';

            $query = mb_split("&", parse_url($request->getRequestURI(), PHP_URL_QUERY));
            if (!empty($query)) foreach ($query as $qr) {
                $vars = mb_split('=', $qr);
                if ($vars[0] != null)
                    $_GET[$vars[0]] = $vars[1];
            }


        if ($request->method() == 'post') {

            if (App::$app->session->get('VERIFIED') === 'TRUE') {
                App::$app->session->unset_key('VERIFIED');

                if (isset($_GET['edit_id'])) {
                    $mode = 'update';
                    $category = Category::findOne(['cat_id' => $_GET['edit_id']]);
                }

                $category->loadData($request->getBody());
                if ($mode == 'update') {
                    $category->update(['cat_id' => $_GET['edit_id']], $request->getBody());
                    App::$app->response->redirect('/officer/categories');
                    exit();
                } else {
                    if ($category->validate() && $category->save()) {
                        App::$app->response->redirect('/officer/categories');
                        exit();
                    }
                }
            }
            return $this->requireVerification($request);
        }
        if (isset($_GET['delete_id'])) {
            if (App::$app->session->get('VERIFIED') === 'TRUE') {
                App::$app->session->unset_key('VERIFIED');

                $delete_id = $_GET['delete_id'];

                // delete all subcategories and guidelines under that category
                foreach (SubCategory::getAllWhere(['cat_id' => $delete_id]) as $subcategory) {
                    foreach (Guideline::getAllWhere(['subcategory_id' => $subcategory->getSubCategoryId()]) as $guideline) {
                        $guideline->update(['guid_id' => $guideline->getGuidId()], ['guid_status' => '4']);
                    }
                    $subcategory->delete(['subcategory_id' => $subcategory->getSubCategoryId()]);
                }
                $category->delete(['cat_id' => $delete_id]);
                App::$app->response->redirect('/officer/categories');
                exit();
            }
            return $this->requireVerification($request);
        }

        $formAttributes = $request->getBody();

        if (isset($formAttributes['email']) && isset($formAttributes['password'])) {
            $loginForm = new LoginForm();
            $loginForm->loadData($request->getBody());
            if ($loginForm->validate() && $loginForm->login()) {
                $delete_id = $formAttributes["delete_id"];
                $category->delete(['cat_id' => $delete_id]);
                App::$app->response->redirect('/officer/categories');
                exit();
            }
        }

        $categories = Category::getAll();

        return $this->render('officer_categories', ['categories' => $categories, 'model' => $category, 'mode' => $mode]);
    }

    public function add_subcategory(Request $request, Response $response)
    {
        $mode = "";
        $subcategory = new SubCategory();
        if (isset($_GET['edit_id'])) {
            $mode = 'update';
            $subcategory = SubCategory::findOne(['sub_category_id' => $_GET['edit_id']]);
        }

        if ($request->method() === 'post') {
            if (App::$app->session->get('VERIFIED') === 'TRUE') {
                App::$app->session->unset_key('VERIFIED');

                $query = mb_split("&", parse_url($request->getRequestURI(), PHP_URL_QUERY));
                if (!empty($query)) foreach ($query as $qr) {
                    $vars = mb_split('=', $qr);
                    $_GET[$vars[0]] = $vars[1];
                }

                if (isset($_GET['edit_id'])) {
                    $mode = 'update';
                    $subcategory = SubCategory::findOne(['sub_category_id' => $_GET['edit_id']]);
                }

                if ($mode === 'update') {
                    $subcategory->update(['sub_category_id' => $_GET['edit_id']], $request->getBody());
                    Notification::addNotification(SubCategory::getCategoryID($_GET['edit_id']), Notification::UPDATE_NOTIFICATION, Notification::SUB_CATEGORY);
                    App::$app->response->redirect('/officer/add-subcategory');
                    exit();
                }
                $subcategory->loadData($request->getBody());
                if ($subcategory->save()) {
                    Notification::addNotification(SubCategory::getCategoryID(DbModel::lastInsertID()), Notification::CREATE_NOTIFICATION, Notification::SUB_CATEGORY);
                    App::$app->response->redirect('/officer/add-subcategory');
                    exit();
                }
            }
            return $this->requireVerification($request);

        }
        if (isset($_GET['delete_id'])) {
            if (App::$app->session->get('VERIFIED') === 'TRUE') {
                App::$app->session->unset_key('VERIFIED');
                $delete_id = $_GET['delete_id'];
                $cat_id = SubCategory::getCategoryID($_GET['delete_id']);

                //delete all the guideline under that subcategory
                foreach (Guideline::getAllWhere(['subcategory_id' => $subcategory->getSubCategoryId()]) as $guideline) {
                    $guideline->update(['guid_id' => $guideline->getGuidId()], ['guid_status' => '4']);
                }

                $subcategory->delete(['sub_category_id' => $delete_id]);
                Notification::addNotification($cat_id, Notification::DELETE_NOTIFICATION, Notification::SUB_CATEGORY);
            }
            return $this->requireVerification($request);
        }


        $categories = Category::getAll();
        $subcategories = SubCategory::getAll();


        return $this->render('officer_add_subcategory', ['subcategories' => $subcategories, 'categories' => $categories, 'model' => $subcategory,]);
    }

    public
    function verify(Request $request, Response $response)
    {
        if (password_verify($_POST['verify'], App::$app->user->getPassword())) {
            App::$app->session->set('VERIFIED', 'TRUE');
            $request_prev = unserialize(App::$app->session->get('REQUEST'));
            App::$app->session->unset_key('REQUEST');

            //setting the previous request and resolving it
            App::$app->router->request = $request_prev;
            App::$app->run();

            exit();
        }
        throw new \Error("Unauthorized Access", 403);

    }

    private
    function requireVerification(Request $request)
    {
        App::$app->session->set('REQUEST', serialize($request));
        $this->setLayout('main');
        return $this->render('officer_verify');
    }
}
