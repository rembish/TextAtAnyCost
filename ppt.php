<?php
// Чтение текста из PPT
// Версия 0.3
// Автор: Алексей Рембиш a.k.a Ramon
// E-mail: alex@rembish.ru
// Copyright 2009

// Чтобы работать с doc, мы дожны уметь работать с WCBFF не так ли?
require_once "cfb.php";

class ppt extends cfb {
	public function parse() {
		parent::parse();

		// В файле обязан быть поток Current User.
		$cuStreamID = $this->getStreamIdByName("Current User");
		if ($cuStreamID === false) { return false; }

		// Получаем этот поток, проверяем хеш (а перед нами ли PowerPoint-презентация?) 
		// и читаем смещение до первой структуры UserEditAtom
		$cuStream = $this->getStreamById($cuStreamID);
		if ($this->getLong(12, $cuStream) == 0xF3D1C4DF) { return false; }
		$offsetToCurrentEdit = $this->getLong(16, $cuStream);

		// Находим в файле поток PowerPoint Document.
		$ppdStreamID = $this->getStreamIdByName("PowerPoint Document");
		if ($ppdStreamID === false) { return false; }
		$ppdStream = $this->getStreamById($ppdStreamID);

		// В нём начинаем искать все UserEditAtom'ы, которые требуются нам для получения
		// смещений до PersistDirectory.
		$offsetLastEdit = $offsetToCurrentEdit;
		$persistDirEntry = array();
		$live = null;
		$offsetPersistDirectory = array();
		do {
			$userEditAtom = $this->getRecord($ppdStream, $offsetLastEdit, 0x0FF5);
			$live = &$userEditAtom;
			array_unshift($offsetPersistDirectory, $this->getLong(12, $userEditAtom));
			$offsetLastEdit = $this->getLong(8, $userEditAtom);
		} while ($offsetLastEdit != 0x00000000);

		// Перебираем все полученные смещения. До этого здесь была *серьёзная* ошибка.
		for ($j = 0; $j < count($offsetPersistDirectory); $j++) {
			$rgPersistDirEntry = $this->getRecord($ppdStream, $offsetPersistDirectory[$j], 0x1772);
			if ($rgPersistDirEntry === false) { return false; }

			// Теперь читаем по четыре байта: первые 20 бит - это начальный ID вхождения в PersistDirectory,
			// следующие 12 - количество последующих смещений.
			for ($k = 0; $k < strlen($rgPersistDirEntry); ) {
				$persist = $this->getLong($k, $rgPersistDirEntry);
				$persistId = $persist & 0x000FFFFF;
				$cPersist = (($persist & 0xFFF00000) >> 20) & 0x00000FFF;
				$k += 4;

				// Заполняем массив PersistDirectory, исходя из полученных данных.
				for ($i = 0; $i < $cPersist; $i++) {
					$offset = $this->getLong($k + $i * 4, $rgPersistDirEntry);
					$persistDirEntry[$persistId + $i] = $this->getLong($k + $i * 4, $rgPersistDirEntry);
				}
				$k += $cPersist * 4;
			}
		}

		// В последней прочитанной записи ищем ID вхождения с DocumentContainer'ом.
		$docPersistIdRef = $this->getLong(16, $live);
		$documentContainer = $this->getRecord($ppdStream, $persistDirEntry[$docPersistIdRef], 0x03E8);

		// Теперь нам нужно пропустить много мусора до SlideList'а.
		$offset = 40 + 8;
		$exObjList = $this->getRecord($documentContainer, $offset, 0x0409);
		if ($exObjList) $offset += strlen($exObjList) + 8;
		$documentTextInfo = $this->getRecord($documentContainer, $offset, 0x03F2);
		$offset += strlen($documentTextInfo) + 8;
		$soundCollection = $this->getRecord($documentContainer, $offset, 0x07E4);
		if ($soundCollection) $offset += strlen($soundCollection) + 8;
		$drawingGroup = $this->getRecord($documentContainer, $offset, 0x040B);
		$offset += strlen($drawingGroup) + 8;
		$masterList = $this->getRecord($documentContainer, $offset, 0x0FF0);
		$offset += strlen($masterList) + 8;
		$docInfoList = $this->getRecord($documentContainer, $offset, 0x07D0);
		if ($docInfoList) $offset += strlen($docInfoList) + 8;
		$slideHF = $this->getRecord($documentContainer, $offset, 0x0FD9);
		if ($slideHF) $offset += strlen($slideHF) + 8;
		$notesHF = $this->getRecord($documentContainer, $offset, 0x0FD9);
		if ($notesHF) $offset += strlen($notesHF) + 8;

		// Избавляемся от прочитанного мусора.
		unset($exObjList, $documentTextInfo, $soundCollection, $drawingGroup, $masterList, $docInfoList, $slideHF, $notesHF);

		// Читаем структуру SlideList.
		$slideList = $this->getRecord($documentContainer, $offset, 0x0FF0);
		$out = "";
		for ($i = 0; $i < strlen($slideList); ) {
			// Читаем текущий блок и определяем, что нам делать по его типу.
			$block = $this->getRecord($slideList, $i);
			switch($this->getRecordType($slideList, $i)) {
				case 0x03F3: # RT_SlidePersistAtom
					// Вариант худший, если перед нами указатель на слайд, тогда мы должны
					// обратиться к PersistDirectory для получения этого слайда.
					$pid = $this->getLong(0, $block);
					$slide = $this->getRecord($ppdStream, @$persistDirEntry[$pid], 0x03EE);

					// Опять пропускаем всякое-разное до структуры Drawing.
					$offset = 32;
					$slideShowSlideInfoAtom = $this->getRecord($slide, $offset, 0x03F9);
					if ($slideShowSlideInfoAtom) $offset += strlen($slideShowSlideInfoAtom) + 8;
					$perSlideHFContainer = $this->getRecord($slide, $offset, 0x0FD9);
					if ($perSlideHFContainer) $offset += strlen($perSlideHFContainer) + 8;
					$rtSlideSyncInfo12 = $this->getRecord($slide, $offset, 0x3714);
					if ($rtSlideSyncInfo12) $offset += strlen($rtSlideSyncInfo12) + 8;

					// Drawing - это объект MS Drawing, который имеет подобную PPT заголовочную структуру.
					// Чтобы не разбирать все возможные вложения структур одна в другую, поищем текст напрямую.
					$drawing = $this->getRecord($slide, $offset, 0x040C);
					$from = 0;
					while(preg_match("#(\xA8|\xA0)\x0F#", $drawing, $pocket, PREG_OFFSET_CAPTURE, $from)) {
						$pocket = @$pocket[1];
						// Обязательно проверим, что заголовок блока начинается с двух "нулей", иначе возможно мы 
						// нашли что-то в середине других данных.
						if (substr($drawing, $pocket[1] - 2, 2) == "\x00\x00") {
							// Читаем либо Plain текст, либо Unicode.
							if (ord($pocket[0]) == 0xA8)
								$out .= htmlspecialchars($this->getRecord($drawing, $pocket[1] - 2, 0x0FA8))." ";
							else
								$out .= $this->unicode_to_utf8($this->getRecord($drawing, $pocket[1] - 2, 0x0FA0))." ";
						}
						// Ищем следующее вхождение
						$from = $pocket[1] + 2;
					}
				break;
				case 0x0FA0: # RT_TextCharsAtom
				// Варианты по проще: мы нашли Unicode-символьное вхождение
					$out .= $this->unicode_to_utf8($block)." ";
				break;
				case 0x0FA8: # RT_TextBytesAtom
				// Или обычный читый текст.
					$out .= htmlspecialchars($block)." ";
				break;
				# some other skipped
			}

			// Сдвигаемся на длину блока с заголовком.
			$i += strlen($block) + 8;
		}

		// Возвращаем UTF-8 текст.
		return html_entity_decode(iconv("windows-1251", "utf-8", $out), ENT_QUOTES, "UTF-8");
	}

	// Дополнительная функция, определяющая длину текущей внутренней структуры.
	// Принимает на вход поток, из которого получать данные, смещение, по которому
	// их читать и тип структуры, по которому проверять читаем ли мы правильную информацию.
	private function getRecordLength($stream, $offset, $recType = null) {
		$rh = substr($stream, $offset, 8);
		if (!is_null($recType) && $recType != $this->getShort(2, $rh))
			return false;
		return $this->getLong(4, $rh);
	}
	// Получение типа текущей структуры в соответствии с "прейскурантом" от MS.
	private function getRecordType($stream, $offset) {
		$rh = substr($stream, $offset, 8);
		return $this->getShort(2, $rh);
	}
	// Получение записи из потока по смещению, возможно заданного типа. Внимание, заголовок
	// назад не передаётся.
	private function getRecord($stream, $offset, $recType = null) {
		$length = $this->getRecordLength($stream, $offset, $recType);
		if ($length === false)
			return false;
		return substr($stream, $offset + 8, $length);
	}
}

// Для тех, кому не нужны классы :)
function ppt2text($filename) {
	$ppt = new ppt;
	$ppt->read($filename);
	return $ppt->parse();
}
