<?php

namespace app\controllers;

use app\models\Weather;
use Yii;
use yii\web\Controller;

class WeatherController extends Controller
{
    public function actionIndex()
    {
        // Получение параметров запроса
        $request = Yii::$app->request;

        // Установка заголовка ответа
        Yii::$app->response->getHeaders()->set('Content-Type', 'application/json; charset=UTF-8');

        // Получение погоды
        $weather = new Weather();
        return $weather->getWeather($request);
    }
}
