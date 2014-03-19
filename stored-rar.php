<?php
// Создание stored-RAR архивов
// Версия 0.3
// Автор: Алексей Рембиш a.k.a Ramon
// E-mail: alex@rembish.ru
// Copyright 2009

// Класс создания RAR-архивов со stored-сжатием
// Пример работы:
// $rar = new store_rar;
//  $rar->create("archive.rar"); # создаём архив
//  $rar->addFile("a.txt");      # пишем в него файл a.txt
//  $rar->addDirectory("b/c");   # создаём в архиве директорию "b" с поддиректорией "c"
//  $rar->addFile("d/e.txt");    # создаём директорию "d" и пишем в неё e.txt
// $rar->close();                # закрываем архив
class store_rar {
	// Указатель на архив
	private $id = null;
	// Внутренняя структура каталогов, чтобы не создавать лишние.
	private $tree = array();

	// Функция, создающая новый архив, после чего записывающая в него обязательные заголовки.
	public function create($filename) {
		$this->id = fopen($filename, "wb");
		if (!$this->id)
			return false;

		$this->tree = array();

		$this->writeHeader(0x72, 0x1a21);
		$this->writeHeader(0x73, 0x0000, array(array(0, 2), array(0, 4)));
		return true;
	}
	// Функция, закрывающая записанный архив
	public function close() {
		fclose($this->id);
	}

	// Функция, что добавляет в архив директорию. Нормально справляется с рекурсивными
	// директориями. Например, для $name = "a/b" создаст директорию "a", а в ней поддиректорию
	// "b". Не будет создавать дубликаты директорий.
	public function addDirectory($name) {
		$name = str_replace("/", "\\", $name);
		if ($name[0] == "\\") $name = substr($name, 1);
		if ($name[strlen($name) - 1] == "\\") $name = substr($name, 0, -1);

		$parts = explode("\\", $name);
		$c = &$this->tree;
		$cname = ""; $delim = "";
		for ($i = 0; $i < count($parts); $i++) {
			$cname .= $delim.$parts[$i];
			if (!isset($c[$parts[$i]])) {
				$c[$parts[$i]] = array();
				$this->writeHeader(0x74, $this->setBits(array(5, 6, 7, 15)), array(
					array(0, 4),					// Packed size = 0 for directories
					array(0, 4),					// Unpacked size = 0 for directories
					array(0, 1),					// Host OS = "MS DOS"
					array(0, 4),					// File CRC = 0 for directories
					array($this->getDateTime(), 4),	// File time = Current date and time in MS DOS format
					array(20, 1),					// RAR version = 2.0
					array(0x30, 1),					// Method = Store
					array(strlen($cname), 2),		// Name size
					array(0x10, 4),					// File attributes = Directory
					$cname,							// Filename = Directory name
				));
			}
			$c = &$c[$parts[$i]];
			$delim = "\\";
		}

		return $name;
	}

	// Функция записывающая файл $name в архив. Если задан параметр $dir, то файл будет записан
	// в соответствующую директорию внутри архива (директория может не существовать до записи
	// файла). $name - путь к файлу на сервере. Файл будет записан с basename($name).
	public function addFile($name, $dir = null) {
		if (!file_exists($name))
			return false;

		$c = &$this->tree;
		if (!is_null($dir)) {
			$dir = $this->addDirectory($dir);

			$parts = explode("\\", $dir);
			for($i = 0; $i < count($parts); $i++)
				$c = &$c[$parts[$i]];
		}

		$fname = pathinfo($name, PATHINFO_BASENAME);
		if (in_array($fname, $c))
			return true;

		$data = file_get_contents($name);
		$size = strlen($data);
		if (!is_null($dir))
			$fname = $dir."\\".$name;

		$this->writeHeader(0x74, $this->setBits(array(15)), array(
			array($size, 4),								// Packed size = File size
			array($size, 4),								// Unpacked size = File size
			array(0, 1),									// Host OS = "MS DOS"
			array(crc32($data), 4),							// File CRC
			array($this->getDateTime(filemtime($name)), 4),	// File time
			array(20, 1),									// RAR version = 2.0
			array(0x30, 1),									// Method = store
			array(strlen($fname), 2),						// Name size
			array(0x20, 4),									// File attributes = Archived
			$fname,											// Filename
		));

		fwrite($this->id, $data);
		$c[] = $fname;

		return true;
	}

	// Внутренняя функция, пишущая заголовок блока в соответствии с форматом RAR.
	// Работает только с тремя типами заголовков: блок-маркер, заголовок архива и
	// заголовок файла - $headType. $headFlags - флаги заголовка, $data - возможно
	// пустой массив дополнительных параметров заголовка, что идут после первых
	// обязательных 7 байт.
	private function writeHeader($headType, $headFlags, $data = array()) {
		if (!in_array($headType, array(0x72, 0x73, 0x74)))
			return false;

		$headSize = 2 + 1 + 2 + 2;
		foreach ($data as $key => $value)
			$headSize += is_array($value) ? $value[1] : strlen($value);

		$header = $this->writeBytesToString(array_merge(array($headType, array($headFlags, 2), array($headSize, 2)), $data));
		$header = ($headType == 0x72 ? "Ra" : $this->getCRC($header)).$header;

		fwrite($this->id, $header);
	}

	// Расчёт CRC для заголовка блока. CRC урезается до 2 байт из 4х.
	private function getCRC($string) {
		$crc = crc32($string);
		return chr($crc & 0xFF).chr(($crc >> 8) & 0xFF);
	}

	// Внутренняя функция записи данных в обратном порядке байтов.
	private function getBytes($data, $bytes = 0) {
		$output = "";
		if (!$bytes)
			$bytes = strlen($bytes);

		if (is_int($data) || is_float($data)) {
			$data = sprintf("%0".($bytes * 2)."x", $data);

			for ($i = 0; $i < strlen($data); $i += 2)
				$output = chr(hexdec(substr($data, $i, 2))).$output;
		} else
			$output = $data;

		return $output;
	}
	// Запись массива данных в байт-строку с учётом размерности переданных данных.
	private function writeBytesToString($data) {
		$output = "";
		for ($i = 0; $i < count($data); $i++) {
			if (is_array($data[$i]))
				$output .= $this->getBytes($data[$i][0], $data[$i][1]);
			else
				$output .= $this->getBytes($data[$i]);
		}

		return $output;
	}

	// Установка соответствующих битов в числе. $bits - массив номеров битов или отдельный номер бита.
	private function setBits($bits) {
		$out = 0;
		if (is_int($bits))
			$bits[] = $bits;

		for ($i = 0; $i < count($bits); $i++)
			$out |= 1 << $bits[$i];

		return $out;
	}

	// Получение даты в 4хбитном формате MSDOS. $time - timestamp, от которого получить дату.
	private function getDateTime($time = null) {
		if (!is_null($time))
			$time = time();

		$dt = getdate();
		$out = $dt["seconds"] | ($dt["minutes"] << 5) | ($dt["hours"] << 11) | ($dt["mday"] << 16) | ($dt["mon"] << 21) | (($dt["year"] - 1980) << 25);
		return $out;
	}
};


//Функция для легкого использования класса
//
//Передаём функции 2 параметра:
//$array — массив файлов и папок для добавления в архив
//$rarfile — имя создаваемого архива  
//На выходе получаем: true — если архив создался, false — если нет 
//
//Пример
//$arrs = array('readme.txt','index.php','/home/another/www/');
//$arhive = 'israr.rar';
//to_rar($arrs,$arhive);

function to_rar($array,$rarfile) {
$rar = new store_rar;
$rar->create($rarfile);
foreach ($array as $value) {
if ($value == '.' OR $value == '..') continue; 
elseif (is_dir($value)) $rar->addFile($value); 
elseif (is_file()) $rar->addDirectory($value);
}
return $rar->close();    
}
?>
