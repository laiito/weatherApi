<?php

namespace app\models;

use DOMDocument;
use Exception;
use Yii;
use yii\web\Request;


/**
 * Неверный параметр запроса
 */
class WrongParameterException extends Exception
{
}

/**
 * Нет данных для ответа
 */
class NoDataException extends Exception
{
}

/**
 * Необходимые для обработки запроса параметры
 */
class WeatherRequestDTO
{
    public string $cityName;
    public string $cityCode;

    public string $fullDate;
    public string $year;
    public string $month;
    public string $day;

    /**
     * Формаирование DTO из объекта запроса
     *
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->cityName = $request->get('city');
        $this->fullDate = $request->get('date');
    }
}

/**
 * Ответ
 */
class WeatherResponseDTO
{
    public string $status = 'ok';
    public int $temp_max;
    public int $temp_min;
    public int $pressure;
    public float $clouds;
}

/**
 * Ответ при возникновении ошибки
 */
class WeatherErrorDTO
{
    public string $status = 'error';
    public string $error;
}

/**
 * Основной класс, релизующий логику API
 */
class Weather
{
    // 24 часа
    const SHORT_TIMEOUT = 86400;
    // 7 суток
    const LONG_TIMEOUT = 604800;

    /**
     * Основной метод API
     *
     * @param Request $request
     * @return string
     */
    public function getWeather(Request &$request): string
    {
        $requestDTO = new WeatherRequestDTO($request);

        $cache = Yii::$app->cache;

        // Запрос к кэшу
        $cacheKey = "{$requestDTO->fullDate}{$requestDTO->cityName}";
        $cacheValue = $cache->get($cacheKey);
        if ($cacheValue) {
            // Установка ответа из кэша
            return $cacheValue;
        }
        // В кэше не найдено

        // Разбор даты на составные части
        list($requestDTO->year, $requestDTO->month, $requestDTO->day) = explode('-', $requestDTO->fullDate);

        try {
            // Проверка корректности даты
            if (!$requestDTO->fullDate || !checkdate($requestDTO->month, $requestDTO->day, $requestDTO->year)) {
                throw new WrongParameterException('wrong date');
            }

            // Поиск города в кэше
            $requestDTO->cityCode = $cache->get($requestDTO->cityName);
            if (!$requestDTO->cityCode) {
                // Город в кэше не найден: получаем значение и кэшируем
                $requestDTO->cityCode = Cities::CITIES[$requestDTO->cityName];
                $cache->set($requestDTO->cityName, $requestDTO->cityCode, Weather::LONG_TIMEOUT);
            }

            // Проверка корректности города
            if (!$requestDTO->cityName || !$requestDTO->cityCode) {
                throw new WrongParameterException('wrong city');
            }

            // Запрос к внешним источникам
            $client = new Client();
            $responseDTO = $client->getFromExternal($requestDTO);
            $today = date('Y-m-d');
            if ($requestDTO->fullDate < $today) {
                $cacheTimeout = Weather::LONG_TIMEOUT;
            } else {
                $cacheTimeout = Weather::SHORT_TIMEOUT;
            }
        } catch (WrongParameterException|NoDataException $e) {
            // Установка сообщения об ошибке
            $responseDTO = new WeatherErrorDTO();
            $responseDTO->error = $e->getMessage();
            $cacheTimeout = Weather::SHORT_TIMEOUT;
        }

        $responseJson = json_encode($responseDTO);
        $cache->set($cacheKey, $responseJson, $cacheTimeout);
        return $responseJson;
    }
}

/**
 * Реализует запросы к внешним сервисам для получения погоды
 */
class Client
{
    private string $firstDate = '1997-04-01';

    /**
     * Получение погоды запросом в зависимости от даты
     *
     * @param WeatherRequestDTO $requestDTO
     * @return WeatherResponseDTO
     * @throws NoDataException
     * @throws WrongParameterException
     */
    public function getFromExternal(WeatherRequestDTO &$requestDTO): WeatherResponseDTO
    {
        $dayBeforeYesterday = date('Y-m-d', strtotime('-2 day'));
        $today = date('Y-m-d');
        $lastForecastDay = date('Y-m-d', strtotime('+7 day'));

        if ($requestDTO->fullDate >= $this->firstDate && $requestDTO->fullDate <= $dayBeforeYesterday) {
            $responseDTO = $this->requestGismeteo($requestDTO);
        } elseif ($requestDTO->fullDate > $dayBeforeYesterday && $requestDTO->fullDate <= $today) {
            $responseDTO = $this->requestWeatherbit($requestDTO);
        } elseif ($requestDTO->fullDate > $today && $requestDTO->fullDate <= $lastForecastDay) {
            $responseDTO = $this->requestVisualcrossing($requestDTO);
        } else {
            throw new WrongParameterException("date must be between {$this->firstDate} and {$lastForecastDay}");
        }

        return $responseDTO;
    }

    /**
     * Получение исторических данных с $firstDate до позавчерашнего дня
     *
     * @param WeatherRequestDTO $requestDTO
     * @return WeatherResponseDTO
     * @throws NoDataException
     */
    private function requestGismeteo(WeatherRequestDTO &$requestDTO): WeatherResponseDTO
    {
        //  Формирования адреса запроса, запрос, разбор ответа
        $queryString = "http://www.gismeteo.ru/diary/{$requestDTO->cityCode}/{$requestDTO->year}/{$requestDTO->month}/";
        $html = file_get_contents($queryString);
        $dom = new DOMDocument;
        $dom->loadHTML($html);
        $tableBody = $dom->getElementsByTagName('tbody');

        if (!$tableBody[0]) {
            // Данные за весь месяц отсутствуют
            throw new NoDataException('no data');
        }

        $tr = $tableBody[0]->getElementsByTagName('tr');

        // Поиск необходимой строки
        $found = false;
        if ($tr[$requestDTO->day - 1]) {
            // Найдена строка по номеру. Проверка, нужной ли дате соответствует строка
            $td = $tr[$requestDTO->day - 1]->getElementsByTagName('td');
            $found = ($td[0]->nodeValue == $requestDTO->day);
        }
        if (!$found) {
            // Поиск строки с нужной датой среди всех строк
            foreach ($tr as $trTry) {
                $td = $trTry->getElementsByTagName('td');
                if ($td[0]->nodeValue == $requestDTO->day) {
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            // Запрошенная дата не найдена
            throw new NoDataException('no data');
        }

        // Запрошенная дата найдена
        $imgClouds = $td[3]->getElementsByTagName('img')[0]->getAttribute('src');
        $cloudsPosition = strrpos($imgClouds, '/');

        $responseDTO = new WeatherResponseDTO();
        $responseDTO->temp_max = $td[1]->nodeValue;
        $responseDTO->temp_min = $td[6]->nodeValue;
        $responseDTO->pressure = $td[2]->nodeValue;
        $cloudsString = substr($imgClouds, $cloudsPosition + 1);

        $cloudsSwitch = ['sun.png' => 0, 'sunc.png' => 25, 'suncl.png' => 50, 'dull.png' => 100];
        $responseDTO->clouds = $cloudsSwitch[$cloudsString];
        return $responseDTO;
    }

    /**
     * Получение погоды за вчера и сегодня
     */
    private function requestWeatherbit(WeatherRequestDTO &$requestDTO): WeatherResponseDTO
    {
        //  Формирования адреса запроса, запрос, разбор ответа
        require __DIR__ . '/../components/apiKeys.php';
        $encodedCity = urlencode($requestDTO->cityName);

        $endDate = date('Y-m-d', strtotime("{$requestDTO->fullDate} +1 day"));
        $queryString = <<<END
            https://api.weatherbit.io/v2.0/history/daily?
            city={$encodedCity}&
            country=Russia&
            start_date={$requestDTO->fullDate}:00&
            end_date={$endDate}:00&key={$WEATHERBIT_API_KEY}
            END;
        $jsonResponse = file_get_contents($queryString);
        $arrayResponse = json_decode($jsonResponse, true)['data'][0];

        $responseDTO = new WeatherResponseDTO();
        $responseDTO->temp_max = $arrayResponse['max_temp'];
        $responseDTO->temp_min = $arrayResponse['min_temp'];
        $responseDTO->pressure = round($arrayResponse['pres'] * 0.75);
        $responseDTO->clouds = $arrayResponse['clouds'];

        return $responseDTO;
    }

    /**
     * Получение прогноза до 7 дней
     */
    private function requestVisualcrossing(WeatherRequestDTO &$requestDTO): WeatherResponseDTO
    {
        //  Формирования адреса запроса, запрос, разбор ответа
        require __DIR__ . '/../components/apiKeys.php';
        $encodedCity = urlencode($requestDTO->cityName);
        $queryString = <<<END
            https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/
            {$encodedCity}/{$requestDTO->fullDate}?include=days&unitGroup=metric&key=$VISUALCROSSING_API_KEY
            END;
        $jsonResponse = file_get_contents($queryString);
        $arrayResponse = json_decode($jsonResponse, true)['days'][0];

        $responseDTO = new WeatherResponseDTO();
        $responseDTO->temp_max = $arrayResponse['tempmax'];
        $responseDTO->temp_min = $arrayResponse['tempmin'];
        $responseDTO->pressure = round($arrayResponse['pressure'] * 0.75);
        $responseDTO->clouds = $arrayResponse['cloudcover'];

        return $responseDTO;
    }
}

/**
 * Класс хранит константы для городов
 */
class Cities
{
    const CITIES = [
        'Альметьевск' => 11940, 'Анапа' => 5211, 'Армавир' => 5220, 'Архангельск' => 3915, 'Астрахань' => 5130,
        'Балаково' => 5018, 'Барнаул' => 4720, 'Белгород' => 5039, 'Бийск' => 4731, 'Братск' => 4746, 'Брянск' => 4258,
        'Владивосток' => 4877, 'Владимир' => 4350, 'Волгоград' => 5089, 'Волгодонск' => 11961, 'Волжский' => 11934,
        'Вологда' => 4278, 'Воронеж' => 5026, 'Геленджик' => 5213, 'Екатеринбург' => 4517, 'Златоуст' => 4563,
        'Иваново' => 4318, 'Ижевск' => 4508, 'Иркутск' => 4787, 'Ишим' => 4544, 'Йошкар-Ола' => 11975, 'Казань' => 4364,
        'Калининград' => 4225, 'Калуга' => 4387, 'Каменск-Уральский' => 4520, 'Кемерово' => 4693, 'Киров' => 4292,
        'Комсомольск-на-Амуре' => 4853, 'Кострома' => 4314, 'Краснодар' => 5136, 'Красноярск' => 4674, 'Курган' => 4569,
        'Курск' => 5010, 'Липецк' => 4437, 'Магнитогорск' => 4613, 'Миасс' => 4566, 'Москва' => 4368,
        'Мурманск' => 3903, 'Нефтеюганск' => 3993, 'Нижневартовск' => 3974, 'Нижнекамск' => 11691,
        'Новокузнецк' => 4721, 'Новороссийск' => 5214, 'Новосибирск' => 4690, 'Омск' => 4578, 'Орел' => 4432,
        'Оренбург' => 5159, 'Орск' => 5163, 'Пенза' => 4445, 'Пермь' => 4476, 'Петрозаводск' => 3934,
        'Прокопьевск' => 11348, 'Псков' => 4114, 'Пятигорск' => 5225, 'Ростов-на-Дону' => 5110, 'Рыбинск' => 4298,
        'Рязань' => 4394, 'Самара' => 4618, 'Санкт-Петербург' => 4079, 'Саранск' => 4401, 'Саратов' => 5032,
        'Смоленск' => 4239, 'Сочи' => 5233, 'Ставрополь' => 5141, 'Стерлитамак' => 4608, 'Сургут' => 3994,
        'Сызрань' => 4448, 'Сыктывкар' => 3989, 'Таганрог' => 5106, 'Тамбов' => 4440, 'Тверь' => 4327,
        'Тольятти' => 4429, 'Томск' => 4652, 'Туапсе' => 5217, 'Тула' => 4392, 'Тюмень' => 4501, 'Ульяновск' => 4407,
        'Уфа' => 4588, 'Ухта' => 3979, 'Хабаровск' => 4862, 'Ханты-Мансийск' => 4003, 'Чебоксары' => 4361,
        'Челябинск' => 4565, 'Череповец' => 4285, 'Шахты' => 5095, 'Энгельс' => 5034, 'Ярославль' => 4313,];
}