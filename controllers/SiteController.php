<?php

namespace app\controllers;

use app\models\Weather;
use yii\web\Controller;

class SiteController extends Controller
{
    public function actionIndex()
    {
        // Получение параметров запроса
        $request = \Yii::$app->request;

        // Установка заголовка ответа
        \Yii::$app->response->getHeaders()->set('Content-Type', 'application/json; charset=UTF-8');

        // Получение погоды
        $weather = new Weather($request);
        return $weather->getWeather();
    }
}
