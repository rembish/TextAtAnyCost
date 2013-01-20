<?php
// Чтение текста из PDF
// Версия 0.3
// Автор: Алексей Рембиш a.k.a Ramon
// E-mail: alex@rembish.ru
// Copyright 2009

function decodeAsciiHex($input) {
    $output = "";

    $isOdd = true;
    $isComment = false;

    for($i = 0, $codeHigh = -1; $i < strlen($input) && $input[$i] != '>'; $i++) {
        $c = $input[$i];

        if($isComment) {
            if ($c == '\r' || $c == '\n')
                $isComment = false;
            continue;
        }

        switch($c) {
            case '\0': case '\t': case '\r': case '\f': case '\n': case ' ': break;
            case '%': 
                $isComment = true;
            break;

            default:
                $code = hexdec($c);
                if($code === 0 && $c != '0')
                    return "";

                if($isOdd)
                    $codeHigh = $code;
                else
                    $output .= chr($codeHigh * 16 + $code);

                $isOdd = !$isOdd;
            break;
        }
    }

    if($input[$i] != '>')
        return "";

    if($isOdd)
        $output .= chr($codeHigh * 16);

    return $output;
}
function decodeAscii85($input) {
    $output = "";

    $isComment = false;
    $ords = array();
    
    for($i = 0, $state = 0; $i < strlen($input) && $input[$i] != '~'; $i++) {
        $c = $input[$i];

        if($isComment) {
            if ($c == '\r' || $c == '\n')
                $isComment = false;
            continue;
        }

        if ($c == '\0' || $c == '\t' || $c == '\r' || $c == '\f' || $c == '\n' || $c == ' ')
            continue;
        if ($c == '%') {
            $isComment = true;
            continue;
        }
        if ($c == 'z' && $state === 0) {
            $output .= str_repeat(chr(0), 4);
            continue;
        }
        if ($c < '!' || $c > 'u')
            return "";

        $code = ord($input[$i]) & 0xff;
        $ords[$state++] = $code - ord('!');

        if ($state == 5) {
            $state = 0;
            for ($sum = 0, $j = 0; $j < 5; $j++)
                $sum = $sum * 85 + $ords[$j];
            for ($j = 3; $j >= 0; $j--)
                $output .= chr($sum >> ($j * 8));
        }
    }
    if ($state === 1)
        return "";
    elseif ($state > 1) {
        for ($i = 0, $sum = 0; $i < $state; $i++)
            $sum += ($ords[$i] + ($i == $state - 1)) * pow(85, 4 - $i);
        for ($i = 0; $i < $state - 1; $i++)
            $ouput .= chr($sum >> ((3 - $i) * 8));
    }

    return $output;
}
function decodeFlate($input) {
    // Наиболее частый тип сжатия потока данных в PDF.
    // Очень просто реализуется функционалом библиотек.
    return @gzuncompress($input);
}

function getObjectOptions($object) {
    // Нам нужно получить параметры текущегго объекта. Параметры
    // находся между ёлочек << и >>. Каждая опция начинается со слэша /.
    $options = array();
    if (preg_match("#<<(.*)>>#ismU", $object, $options)) {
        // Отделяем опции друг от друга по /. Первую пустую удаляем из массива.
        $options = explode("/", $options[1]);
        @array_shift($options);

        // Далее создадим удобный для будущего использования массив
        // свойств текущего объекта. Параметры вида "/Option N" запишем
        // в хэш, как "Option" => N, свойства типа "/Param", как
        // "Param" => true.
        $o = array();
        for ($j = 0; $j < @count($options); $j++) {
            $options[$j] = preg_replace("#\s+#", " ", trim($options[$j]));
            if (strpos($options[$j], " ") !== false) {
                $parts = explode(" ", $options[$j], 2);
                $o[$parts[0]] = $parts[1];
            } else
                $o[$options[$j]] = true;
        }
        $options = $o;
        unset($o);
    }

    // Возращаем массив найденных параметров.
    return $options;
}
function getDecodedStream($stream, $options) {
    // Итак, перед нами поток, возможно кодированный каким-нибудь
    // методом сжатия, а то и несколькими. Попробуем расшифровать.
    $data = "";
    // Если у текущего потока есть свойство Filter, то он точно
    // сжат или зашифрован. Иначе, просто возвращаем содержимое
    // потока назад.
    if (empty($options["Filter"]))
        $data = $stream;
    else {
        // Если в опциях есть длина потока данных, то нам нужно обрезать данные
        // по заданной длине, а не то расшифровать не сможем или ещё какая
        // беда случится.
        $length = !empty($options["Length"]) && strpos($options["Length"], " ") === false ? $options["Length"] : strlen($stream);
        $_stream = substr($stream, 0, $length);

        // Перебираем опции на предмет наличия указаний на сжатие данных в текущем
        // потоке. PDF поддерживает много всего и разного, но текст кодируется тремя
        // вариантами: ASCII Hex, ASCII 85-base и GZ/Deflate. Ищем соответствующие
        // ключи и применяем соответствующие функции для расжатия. Есть ещё вариант
        // Crypt, но распознавать шифрованные PDF'ки мы не будем.
        foreach ($options as $key => $value) {
            if ($key == "ASCIIHexDecode")
                $_stream = decodeAsciiHex($_stream);
            if ($key == "ASCII85Decode")
                $_stream = decodeAscii85($_stream);
            if ($key == "FlateDecode")
                $_stream = decodeFlate($_stream);
        }
        $data = $_stream;
    }
    // Возвращаем результат наших злодейств.
    return $data;
}
function getDirtyTexts(&$texts, $textContainers) {
    // Итак, у нас есть массив контейнеров текста, выдранных из пары BT и ET.
    // Наша новая задача, найти в них текст, который отображается просмотрщиками
    // на экране. Вариантов много, рассмотрим пару: [...] TJ и Td (...) Tj
    for ($j = 0; $j < count($textContainers); $j++) {
        // Добавляем найденные кусочки "грязных" данных к общему массиву
        // текстовых объектов.
        if (preg_match_all("#\[(.*)\]\s*TJ#ismU", $textContainers[$j], $parts))
            $texts = array_merge($texts, @$parts[1]);
        elseif(preg_match_all("#Td\s*(\(.*\))\s*Tj#ismU", $textContainers[$j], $parts))
            $texts = array_merge($texts, @$parts[1]);
    }
}
function getCharTransformations(&$transformations, $stream) {
    // О Мама Миа! Этого насколько я мог увидеть, никто не реализовывал на PHP, по крайней
    // мере в открытом доступе. Сейчас мы займёмся весёлым, начнём искать по потокам
    // трансформации символов. Под трансформацией я имею ввиду перевод одного символа в hex-
    // представлении в другой, или даже в некоторую последовательность.

    // Нас интересуют следующие поля, которые мы должны отыскать в текущем потоке.
    // Данные между beginbfchar и endbfchar преобразовывают один hex-код в другой (или 
    // последовательность кодов) по отдельности. Между beginbfrange и endbfrange организуется
    // преобразование над последовательностями данных, что сокращает  количество определений.
    preg_match_all("#([0-9]+)\s+beginbfchar(.*)endbfchar#ismU", $stream, $chars, PREG_SET_ORDER);
    preg_match_all("#([0-9]+)\s+beginbfrange(.*)endbfrange#ismU", $stream, $ranges, PREG_SET_ORDER);

    // Вначале обрабатываем отдельные символы. Строка преобразования выглядит так:
    // - <0123> <abcd> -> 0123 преобразовывается в abcd;
    // - <0123> <abcd6789> -> 0123 преобразовывается в несколько символов (в данном случае abcd и 6789)
    for ($j = 0; $j < count($chars); $j++) {
        // Перед списком данных, есть число обозначающее количество строк, которые нужно
        // прочитать. Мы будем брать его в рассчёт.
        $count = $chars[$j][1];
        $current = explode("\n", trim($chars[$j][2]));
        // Читаем данные из каждой строчки.
        for ($k = 0; $k < $count && $k < count($current); $k++) {
            // После этого записываем новую найденную трансформацию. Не забываем, что
            // если символов меньше четырёх, мы должны дописать нули.
            if (preg_match("#<([0-9a-f]{2,4})>\s+<([0-9a-f]{4,512})>#is", trim($current[$k]), $map))
                $transformations[str_pad($map[1], 4, "0")] = $map[2];
        }
    }
    // Теперь обратимся к последовательностям. По документации последовательности бывают
    // двух видов, а именно:
    // - <0000> <0020> <0a00> -> в этом случае <0000> будет заменено на <0a00>, <0001> на <0a01> и
    //   так далее до <0020>, которое превратится в <0a20>.
    // - <0000> <0002> [<abcd> <01234567> <8900>] -> тут всё работает чуть по другому. Смотрим, сколько
    //   элементов находится между <0000> и <0002> (вместе с 0001 три). Потом каждому из элементов
    //   присваиваем значение из квадратных скобок: 0000 -> abcd, 0001 -> 0123 4567, а 0002 -> 8900.
    for ($j = 0; $j < count($ranges); $j++) {
        // Опять сверяемся с количеством элементов для трансформации.
        $count = $ranges[$j][1];
        $current = explode("\n", trim($ranges[$j][2]));
        // Перебираем строчки.
        for ($k = 0; $k < $count && $k < count($current); $k++) {
            // В данном случае последовательность первого типа.
            if (preg_match("#<([0-9a-f]{4})>\s+<([0-9a-f]{4})>\s+<([0-9a-f]{4})>#is", trim($current[$k]), $map)) {
                // Переводим данные в 10-чную систему счисления, так проще прошагать циклом.
                $from = hexdec($map[1]);
                $to = hexdec($map[2]);
                $_from = hexdec($map[3]);

                // В массив трансформаций добавляем все элементы между началом и концом последовательности.
                // По документации мы должны добавить ведущие нули, если длина hex-кода меньше четырёх символов.
                for ($m = $from, $n = 0; $m <= $to; $m++, $n++)
                    $transformations[sprintf("%04X", $m)] = sprintf("%04X", $_from + $n);
            // Второй вариант.
            } elseif (preg_match("#<([0-9a-f]{4})>\s+<([0-9a-f]{4})>\s+\[(.*)\]#ismU", trim($current[$k]), $map)) {
                // Также начало и конец последовательности. Бъём данные в квадратных скобках
                // по (около)пробельным символам.
                $from = hexdec($map[1]);
                $to = hexdec($map[2]);
                $parts = preg_split("#\s+#", trim($map[3]));
                
                // Обходим данные и присваиваем соответствующие данные их новым значениям.
                for ($m = $from, $n = 0; $m <= $to && $n < count($parts); $m++, $n++)
                    $transformations[sprintf("%04X", $m)] = sprintf("%04X", hexdec($parts[$n]));
            }
        }
    }
}
function getTextUsingTransformations($texts, $transformations) {
    // Начинаем второй этап - получение текста из "грязных" данных.
    // В PDF "грязные" текстовые строки могут выглядеть следующим образом:
    // - (I love)10(PHP) - в данном случае в () находятся текстовые данные,
    //   а 10 являет собой величину проблема.
    // - <01234567> - в данном случае мы имеем дело с двумя символами,
    //   в их HEX-представлении: 0123 и 4567. Оба символа следует проверить
    //   на наличие замещений в таблице трансформаций.
    // - (Hello, \123world!) - \123 здесь символ в 8-чной системе счисления,
    //   его также требуется верно обработать.

    // Что ж поехали. Начинаем потихоньку накапливать текстовые данные,
    // перебирая "грязные" текстовые кусочки"
    $document = "";
    for ($i = 0; $i < count($texts); $i++) {
        // Нас интересуют две ситуации, когда текст находится в <> (hex) и в
        // () (plain-представление.
        $isHex = false;
        $isPlain = false;

        $hex = "";
        $plain = "";
        // Посимвольно сканируем текущий текстовый кусок.
        for ($j = 0; $j < strlen($texts[$i]); $j++) {
            // Выбираем текущий символ
            $c = $texts[$i][$j];
            // ...и определяем, что нам с ним делать.
            switch($c) {
                // Перед нами начинаются 16-чные данные
                case "<":
                    $hex = "";
                    $isHex = true;
                break;
                // Hex-данные закончились, будем их разбирать.
                case ">":
                    // Бъём строку на кусочки по 4 символа...
                    $hexs = str_split($hex, 4);
                    // ...и смотрим, что мы можем с каждым кусочком сделать
                    for ($k = 0; $k < count($hexs); $k++) {
                        // Возможна ситуация, что в кусочке меньше 4 символов, документация
                        // говорит нам дополнить кусок справа нулями.
                        $chex = str_pad($hexs[$k], 4, "0");
                        // Проверяем наличие данного hex-кода в трансформациях. В случае
                        // успеха, заменяем кусок на требуемый.
                        if (isset($transformations[$chex]))
                            $chex = $transformations[$chex];
                        // Пишем в выходные данные новый Unicode-символ.
                        $document .= html_entity_decode("&#x".$chex.";");
                    }
                    // Hex-данные закончились, не забываем сказать об этом коду.
                    $isHex = false;
                break;
                // Начался кусок "чистого" текста
                case "(":
                    $plain = "";
                    $isPlain = true;
                break;
                // Ну и как водится, этот кусок когда-нибудь закончится.
                case ")":
                    // Добавляем полученный текст в выходной поток.
                    $document .= $plain;
                    $isPlain = false;
                break;
                // Символ экранирования, глянем, что стоит за ним.
                case "\\":
                    $c2 = $texts[$i][$j + 1];
                    // Если это \ или одна из круглых скобок, то нужно их вывести, как есть.
                    if (in_array($c2, array("\\", "(", ")"))) $plain .= $c2;
                    // Возможно, это пробельный символ или ещё какой перевод строки, обрабатываем.
                    elseif ($c2 == "n") $plain .= '\n';
                    elseif ($c2 == "r") $plain .= '\r';
                    elseif ($c2 == "t") $plain .= '\t';
                    elseif ($c2 == "b") $plain .= '\b';
                    elseif ($c2 == "f") $plain .= '\f';
                    // Может случится, что за \ идёт цифра. Таких цифр может быть до 3, они являются
                    // кодом символа в 8-чной системе счисления. Распарсим и их.
                    elseif ($c2 >= '0' && $c2 <= '9') {
                        // Нам нужны три цифры не более, и именно цифры.
                        $oct = preg_replace("#[^0-9]#", "", substr($texts[$i], $j + 1, 3));
                        // Определяем сколько символов мы откусили, чтобы сдвинуть позицию
                        // "текущего символа" правильно.
                        $j += strlen($oct) - 1;
                        // В "чистый" текст пишем соответствующий символ.
                        $plain .= html_entity_decode("&#".octdec($oct).";");
                    }
                    // Мы сдвинули позицию "текущего символа" не меньше, чем на один, парсер
                    // узнай об этом.
                    $j++;
                break;

                // Если же перед нами что-то другое, то пишем текущий символ либо во
                // временную hex-строку (если до этого был символ <),
                default:
                    if ($isHex)
                        $hex .= $c;
                    // либо в "чистую" строку, если была открыта круглая скобка.
                    if ($isPlain)
                        $plain .= $c;
                break;
            }
        }
        // Блоки текста отделяем переводами строк.
        $document .= "\n";
    }

    // Возвращаем полученные текстовые данные.
    return $document;
}

function pdf2text($filename) {
    // Читаем данные из pdf-файла в строку, учитываем, что файл может содержать
    // бинарные потоки.
    $infile = @file_get_contents($filename, FILE_BINARY);
    if (empty($infile))
        return "";

    // Проход первый. Нам требуется получить все текстовые данные из файла.
    // В 1ом проходе мы получаем лишь "грязные" данные, с позиционированием,
    // с вставками hex и так далее.
    $transformations = array();
    $texts = array();

    // Для начала получим список всех объектов из pdf-файла.
    preg_match_all("#obj(.*)endobj#ismU", $infile, $objects);
    $objects = @$objects[1];

    // Начнём обходить, то что нашли - помимо текста, нам может попасться
    // много всего интересного и не всегда "вкусного", например, те же шрифты.
    for ($i = 0; $i < count($objects); $i++) {
        $currentObject = $objects[$i];

        // Проверяем, есть ли в текущем объекте поток данных, почти всегда он
        // сжат с помощью gzip.
        if (preg_match("#stream(.*)endstream#ismU", $currentObject, $stream)) {
            $stream = ltrim($stream[1]);

            // Читаем параметры данного объекта, нас интересует только текстовые
            // данные, поэтому делаем минимальные отсечения, чтобы ускорить
            // выполнения
            $options = getObjectOptions($currentObject);
            if (!(empty($options["Length1"]) && empty($options["Type"]) && empty($options["Subtype"])))
                continue;

            // Итак, перед нами "возможно" текст, расшифровываем его из бинарного
            // представления. После этого действия мы имеем дело только с plain text.
            $data = getDecodedStream($stream, $options); 
            if (strlen($data)) {
                // Итак, нам нужно найти контейнер текста в текущем потоке.
                // В случае успеха найденный "грязный" текст отправится к остальным
                // найденным до этого
                if (preg_match_all("#BT(.*)ET#ismU", $data, $textContainers)) {
                    $textContainers = @$textContainers[1];
                    getDirtyTexts($texts, $textContainers);
                // В противном случае, пытаемся найти символьные трансформации,
                // которые будем использовать во втором шаге.
                } else
                    getCharTransformations($transformations, $data);
            }
        }
    }

    // По окончанию первичного парсинга pdf-документа, начинаем разбор полученных
    // текстовых блоков с учётом символьных трансформаций. По окончанию, возвращаем
    // полученный результат.
    return getTextUsingTransformations($texts, $transformations);
}
?>
