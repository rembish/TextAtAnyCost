<?php
// Чтение текста из DOC
// Версия 0.4
// Автор: Алексей Рембиш a.k.a Ramon
// E-mail: alex@rembish.ru
// Copyright 2009

// Чтобы работать с doc, мы дожны уметь работать с WCBFF не так ли?
require_once "cfb.php";

// Класс для работы с Microsoft Word Document (в народе doc), расширяет
// Windows Compound Binary File Format. Давайте попробуем найти текст и
// здесь
class doc extends cfb {
	// Функция parse расширяет родительскую функцию и на выходе получает
	// текст из данного файла. Если что-то пошло не так - возвращает false
	public function parse() {
		parent::parse();

		// Для чтения DOC'а нам нужны два потока - WordDocument и 0Table или
		// 1Table в зависимости от ситуации. Для начала найдћм первый - в нћм
		// (потоке) разбросаны кусочки текста, которые нам нужной поймать.
		$wdStreamID = $this->getStreamIdByName("WordDocument");
		if ($wdStreamID === false) { return false; }

		// Поток нашли, читаем его в переменную
		$wdStream = $this->getStreamById($wdStreamID);

		// Далее нам нужно получить кое-что из FIB - специальный блок под названием
		// File Information Block в начале потока WordDocument.
		$bytes = $this->getShort(0x000A, $wdStream);
		// Считываем какую именно таблицу нам нужно будет читать - первую или нулевую.
		// Для этого прочитаем один маленький бит из заголовка по известному смещению.
		$fWhichTblStm = ($bytes & 0x0200) == 0x0200;

		// Теперь нам нужно узнать позицию CLX в табличном потоке. Ну и размер этого самого
		// CLX - пусть ему пусто будет.
		$fcClx = $this->getLong(0x01A2, $wdStream);
		$lcbClx = $this->getLong(0x01A6, $wdStream);

		// Читаем несколько значений, чтобы отделить позиции от размерности в clx
		$ccpText = $this->getLong(0x004C, $wdStream);
		$ccpFtn = $this->getLong(0x0050, $wdStream);
		$ccpHdd = $this->getLong(0x0054, $wdStream);
		$ccpMcr = $this->getLong(0x0058, $wdStream);
		$ccpAtn = $this->getLong(0x005C, $wdStream);
		$ccpEdn = $this->getLong(0x0060, $wdStream);
		$ccpTxbx = $this->getLong(0x0064, $wdStream);
		$ccpHdrTxbx = $this->getLong(0x0068, $wdStream);

		// С помощью вышенайденных значений, находим значение последнего CP - character position
		$lastCP = $ccpFtn + $ccpHdd + $ccpMcr + $ccpAtn + $ccpEdn + $ccpTxbx + $ccpHdrTxbx;
		$lastCP += ($lastCP != 0) + $ccpText;

		// Находим в файле нужную нам табличку.
		$tStreamID = $this->getStreamIdByName(intval($fWhichTblStm)."Table");
		if ($tStreamID === false) { return false; }

		// И считываем из нећ поток в переменную
		$tStream = $this->getStreamById($tStreamID);
		// Потом находим в потоке CLX
		$clx = substr($tStream, $fcClx, $lcbClx);

		// А теперь нам в CLX (complex, ага) нужно найти кусок со смещениями и размерностями
		// кусочков текста.
		$lcbPieceTable = 0;
		$pieceTable = "";

		// Отмечу, что здесь вааааааааааще жопа. В документации на сайте толком не сказано
		// сколько гона может быть до pieceTable в этом CLX, поэтому будем исходить из тупого
		// перебора - ищем возможное начало pieceTable (обязательно начинается на 0х02), затем
		// читаем следующие 4 байта - размерность pieceTable. Если размерность по факту и
		// размерность, записанная по смещению, то бинго! мы нашли нашу pieceTable. Нет?
		// ищем дальше.

		$from = 0;
		// Ищем 0х02 с текущего смещения в CLX
		while (($i = strpos($clx, chr(0x02), $from)) !== false) {
			// Находим размер pieceTable
			$lcbPieceTable = $this->getLong($i + 1, $clx);
			// Находим pieceTable
			$pieceTable = substr($clx, $i + 5);

			// Если размер фактический отличается от нужного, то это не то -
			// едем дальше.
			if (strlen($pieceTable) != $lcbPieceTable) {
				$from = $i + 1;
				continue;
			}
			// Хотя нет - вроде нашли, break, товарищи!
			break;
		}

		// Теперь заполняем массив character positions, пока не наткнћмся
		// на последний CP.
		$cp = array(); $i = 0;
		while (($cp[] = $this->getLong($i, $pieceTable)) != $lastCP)
			$i += 4;
		// Остаток идћт на PCD (piece descriptors)
		$pcd = str_split(substr($pieceTable, $i + 4), 8);

		$text = "";
		// Ура! мы подошли к главному - чтение текста из файла.
		// Идћм по декскрипторам кусочков
		for ($i = 0; $i < count($pcd); $i++) {
			// Получаем слово со смещением и флагом компрессии
			$fcValue = $this->getLong(2, $pcd[$i]);
			// Смотрим - что перед нами тупой ANSI или Unicode
			$isANSI = ($fcValue & 0x40000000) == 0x40000000;
			// Остальное без макушки идћт на смещение
			$fc = $fcValue & 0x3FFFFFFF;

			// Получаем длину кусочка текста
			$lcb = $cp[$i + 1] - $cp[$i];
			// Если перед нами Unicode, то мы должны прочитать в два раза больше файлов
			if (!$isANSI)
				$lcb *= 2;
			// Если ANSI, то начать в два раза раньше.
			else
				$fc /= 2;

			// Читаем кусок с учћтом смещения и размера из WordDocument-потока
			$part = substr($wdStream, $fc, $lcb);
			// Если перед нами Unicode, то преобразовываем его в нормальное состояние
			if (!$isANSI)
				$part = $this->unicode_to_utf8($part);

			// Добавляем кусочек к общему тексту
			$text .= $part;
		}

		// Удаляем из файла вхождения с внедрћнными объектами
		$text = preg_replace("/HYPER13 *(INCLUDEPICTURE|HTMLCONTROL)(.*)HYPER15/iU", "", $text);
		$text = preg_replace("/HYPER13(.*)HYPER14(.*)HYPER15/iU", "$2", $text);
		// Возвращаем результат
		return $text;
	}
	// Функция преобразования из Unicode в UTF8, а то как-то не айс.
	private function unicode_to_utf8($in) {
		$out = "";
		// Идћм по двухбайтовым последовательностям
		for ($i = 0; $i < strlen($in); $i += 2) {
			$cd = substr($in, $i, 2);

			// Если верхний байт нулевой, то перед нами ANSI
			if (ord($cd[1]) == 0) {
				// В случае, если ASCII-значение нижнего байта выше 32, то пишем как есть.
				if (ord($cd[0]) >= 32)
					$out .= $cd[0];

				// В противном случае проверяем символы на внедрћнные команды (список можно
				// дополнить и пополнить).
				switch (ord($cd[0])) {
					case 0x0D: case 0x07: $out .= "\n"; break;
					case 0x08: case 0x01: $out .= ""; break;
					case 0x13: $out .= "HYPER13"; break;
					case 0x14: $out .= "HYPER14"; break;
					case 0x15: $out .= "HYPER15"; break;
				}
			} else // Иначе преобразовываем в HTML entity
				$out .= html_entity_decode("&#x".sprintf("%04x", $this->getShort(0, $cd)).";");
		}

		// И возвращаем результат
		return $out;
	}
}

// Функция для преобразования doc в plain-text. Для тех, кому "не нужны классы".
function doc2text($filename) {
	$doc = new doc;
	$doc->read($filename);
	return $doc->parse();
}
?>
