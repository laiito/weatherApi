<?php

namespace app\models;

use DOMDocument;
use yii\base\Model;

class Weather extends Model
{
    private $response = [];
    private $city;
    private $date;
    private $cacheTimeout;

    // 24 часа
    const SHORT_TIMEOUT = 86400;
    // 7 суток
    const LONG_TIMEOUT = 604800;

    public function __construct(&$request)
    {
        // Чтение параметров запроса
        $this->city = $request->get('city');
        $this->date = $request->get('date');
    }

    public function getWeather()
    {
        $cache = \Yii::$app->cache;

        // Запрос к кэшу
        $cacheKey = "{$this->date}{$this->city}";
        $cacheValue = $cache->get($cacheKey);
        if ($cacheValue) {
            // Установка ответа из кэша
            $this->response['json'] = $cacheValue;
        } else {
            // В кэше не найдено 

            // Разбор даты на составные части
            list($year, $month, $day) = explode('-', $this->date);

            // Проверка корректности даты
            if ($this->date && checkdate($month, $day, $year)) {

                // Поиск города в кэше
                $cacheCity = $cache->get($this->city);
                if ($cacheCity) {
                    // Город в кэше найден
                    $cacheCity = $cacheCity;
                } else {
                    // Город в кэше не найден: получаем значение и кэшируем
                    require __DIR__ . '/../components/cities.php';
                    $cacheCity = CITIES[$this->city];
                    $cache->set($this->city, $cacheCity, Weather::LONG_TIMEOUT);
                }

                // Проверка корректности города
                if ($this->city && $cacheCity) {
                    $dayBeforeYesterday = date('Y-m-d', strtotime('-2 day'));
                    $today = date('Y-m-d');
                    $lastForecastDay = date('Y-m-d', strtotime('+7 day'));

                    // Получение погоды запросом в зависимости от даты
                    if ($this->date >= '1997-04-01' && $this->date <= $dayBeforeYesterday) {
                        $this->requestGismeteo($cacheCity, $year, $month, $day);
                    } elseif ($this->date > $dayBeforeYesterday && $this->date <= $today) {
                        $this->requestWeatherbit();
                    } elseif ($this->date > $today && $this->date <= $lastForecastDay) {
                        $this->requestVisualcrossing();
                    } else {
                        $this->setError("date must be between 1997-04-01 and {$lastForecastDay}");
                    }
                } else {
                    $this->setError('wrong city');
                }
            } else {
                $this->setError('wrong date');
            }
            $this->response['json'] = json_encode($this->response);
            $cache->set($cacheKey, $this->response['json'], $this->cacheTimeout);
        }
        return $this->response['json'];
    }

    // Установка сообщения об ошибке
    private function setError($errorText)
    {
        $this->response['status'] = 'error';
        $this->response['error'] = $errorText;

        $this->cacheTimeout = Weather::SHORT_TIMEOUT;
    }

    // Получение исторических данных с 1997-04-01 до позавчерашнего дня
    private function requestGismeteo($city, $year, $month, $day)
    {
        //  Формирования адреса запроса, запрос, разбор ответа
        $queryString = "http://www.gismeteo.ru/diary/{$city}/{$year}/{$month}/";
        $html = file_get_contents($queryString);

        $dom = new DOMDocument;
        $dom->loadHTML($html);
        $tableBody = $dom->getElementsByTagName('tbody');
        $tr = $tableBody[0]->getElementsByTagName('tr');
        $td = $tr[$day - 1]->getElementsByTagName('td');

        $this->response['status'] = 'ok';
        $this->response['temperature'] = $td[1]->nodeValue;

        $this->cacheTimeout = Weather::LONG_TIMEOUT;
    }

    // Получение погоды за вчера и сегодня
    private function requestWeatherbit()
    {
        //  Формирования адреса запроса, запрос, разбор ответа
        require __DIR__ . '/../components/apiKeys.php';
        $encodedCity = urlencode($this->city);

        $endDate = date('Y-m-d', strtotime("{$this->date} +1 day"));
        $queryString = "https://api.weatherbit.io/v2.0/history/daily?city={$encodedCity}&country=Russia&start_date={$this->date}:00&end_date={$endDate}:00&key={$WEATHERBIT_API_KEY}";
        $jsonResponse = file_get_contents($queryString);
        $arrayResponse = json_decode($jsonResponse, true);

        $this->response['status'] = 'ok';
        $this->response['temperature'] = $arrayResponse['data'][0]['max_temp'];
        if ($this->response['temperature'] > 0) {
            $this->response['temperature'] = "+{$this->response['temperature']}";
        }

        $this->cacheTimeout = Weather::SHORT_TIMEOUT;
    }

    // Получение прогноза до 7 дней
    private function requestVisualcrossing()
    {
        //  Формирования адреса запроса, запрос, разбор ответа
        require __DIR__ . '/../components/apiKeys.php';
        $encodedCity = urlencode($this->city);
        $queryString = "https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/{$encodedCity}/{$this->date}?include=days&unitGroup=metric&key=$VISUALCROSSING_API_KEY";
        $jsonResponse = file_get_contents($queryString);
        $arrayResponse = json_decode($jsonResponse, true);

        $this->response['status'] = 'ok';
        $this->response['temperature'] = $arrayResponse['days'][0]['tempmax'];
        if ($this->response['temperature'] > 0) {
            $this->response['temperature'] = "+{$this->response['temperature']}";
        }
    }
}
