<?php
/**
 * Класс для прозрачной работы с MySQL через memcache.
 * Автор: Павел Дубровский, dubrovsky.pn at gmail dot com
 */

class cache {
	private static $connected;
	private static $memcache;
	private static $flag;
	private static $sql_connection;
	private static $main_connect;
	private static $CI;
	
	public function __construct(){
		cache::$CI =& get_instance();
		cache::$CI->load->config('cache', TRUE);
	}

	/**
	 * Если memcached неинициализирован - пытается инициализировать подключения.<br>
	 * Внимание: функция не проверяет возможность установления соединения, так как в большинстве случаев это приводит к большим задержкам. Предполагается, что вы знаете, какие memcached-сервера вам доступны.
	 * @return boolean - TRUE, если memcache разрешён, доступен и указаны параметры хотя бы одного сервера.
	 */
	
	private static function init(){
		if (cache::$connected) return (true); //Уже присоединились, спасибо
		
		if (cache::$CI->config->item('cache')['MEMCACHE']==1 && class_exists('Memcache')){//Проверка доступности и включённости Memcache
			if (cache::$CI->config->item('cache')['memcache_servers']!==null){
				cache::$flag=(cache::$CI->config->item('cache')['memcache_compress']===true)?MEMCACHE_COMPRESSED:0;
				cache::$memcache = new Memcache;
				cache::$connected = false;
				foreach (cache::$CI->config->item('cache')['memcache_servers'] as $connection) {
					$host=$connection[0];
					$port=($connection[1]!==null)?$connection[1]:11211;
					$persistent=($connection[2]!==null)?$connection[2]:FALSE;
					$weight=($connection[3]!==null)?$connection[3]:1;
					cache::$memcache->addServer($host,$port,$persistent,$weight);
				}
				cache::$connected=TRUE;
				return (cache::$connected);
			} else {
				return (false);
			}
		} else {
			return (false);
		}
	}

	/**
	 * Устанавливает в memcache пару ключ-значение
	 * @param string $key - ключ
	 * @param variable $value - значение
	 * @return boolean - удалось ли занести значение в кеш
	 */
	
	private static function set ($key,$value,$tags=array()){
		
		if (cache::$CI->config->item('cache')['MEMCACHE']==1){
			if (cache::init()){
				cache::$memcache->set($key,$value,cache::$flag);
				/*Добавляем теги*/
				if (count($tags)>0){
					foreach ($tags as $tag){
						$tag_keys=cache::get(cache::qhash($tag));
						if (!$tag_keys) $tag_keys=array();
						if (!in_array($key, $tag_keys)){
							$tag_keys=array_merge($tag_keys,(array)$key);
							cache::set(cache::qhash($tag), $tag_keys);//Добавили ключ
						}
					}
				}
				return (true);
			} else {
				return (false);
			}
		} else {
			return (false);
		}
	}

	/**
	 * Получает из memcache значение по ключу
	 * @param string $key - ключ для возврата
	 * @return boolean - если значение получено, возвращает его, иначе возвращает FALSE
	 */
	
	private static function get ($key){
		
		if (cache::$CI->config->item('cache')['MEMCACHE']==1){
			return(cache::init())?cache::$memcache->get($key,cache::$flag):false;
		} else {
			return (false);
		}
	}

	/**
	 * Полностью сбрасывает кеш memcache
	 * @return boolean - статус операции
	 */
	
	private static function flush(){
		
		if (cache::$CI->config->item('cache')['MEMCACHE']==1){
			return (cache::init())?cache::$memcache->flush():false;
		} else {
			return (false);
		}
	}

	/**
	 * Удаляет значение по ключу
	 * @param string $key - ключ на удаление
	 * @return boolean - статус операции
	 */
	
	private static function delete($key){
		
		if (cache::$CI->config->item('cache')['MEMCACHE']==1){
			return (cache::init())?cache::$memcache->delete($key,0):false;
		} else {
			return (false);
		}
	}

	/**
	 * Хеширует ключи, заносимые в кеш. Выделено в отдельную функцию для удобства отладки.
	 * @param string $query
	 * @return string
	 */
	
	private static function qhash($query){
		return (md5($query));
	}

	/**
	 * Если подключение к БД недоступно, пытается установить подключение.
	 * @return boolean - статус операции
	 */
	
	private static function init_db(){
		if (cache::$sql_connection) return (true); else {
			
			setlocale(LC_ALL, 'ru_RU.UTF-8');
			cache::$main_connect=(cache::$CI->config->item('cache')['DB_PCONNECTION']==1)?mysqli_connect("p:{cache::$CI->config->item('cache')['DB_HOST']}",cache::$CI->config->item('cache')['DB_LOGIN'],cache::$CI->config->item('cache')['DB_PASSWORD'],cache::$CI->config->item('cache')['DB_NAME']):mysqli_connect(cache::$CI->config->item('cache')['DB_HOST'],cache::$CI->config->item('cache')['DB_LOGIN'],cache::$CI->config->item('cache')['DB_PASSWORD'],cache::$CI->config->item('cache')['DB_NAME']);
			if (cache::$main_connect) {
				mysqli_query(cache::$main_connect,"SET NAMES 'utf8' COLLATE 'utf8_bin';");
				cache::$sql_connection=true;
				return (true);
			} else {
				return (false);// or die ('Can\'t connect to database!');
			}
		}
	}

	/**
	 * Выполняет запрос к БД. Если соединение с БД не удаётся установить, останавливает выполнение.
	 * @param string $query - запрос SQL
	 * @return boolean - результат запроса (данные, либо ошибка).
	 */
	
	private static function sql_query ($query){
		if (cache::init_db()) {
			return(mysqli_query(cache::$main_connect,$query));
		} else {
			die ('Can\'t connect to database!');
		}
	}

	/**
	 * Выполняет выборку. Функция проверяет, есть ли результат выборки в кеше, если нет - делает запрос в БД, кешируя результат.
	 * @param string $query - SQL Query (только SELECT!)
	 * @param string $ignore_cache - TRUE: игнорировать кеш, делая запрос напрямую в БД (полезно для часто обновляемых значений). FALSE: проверять кеш (по умолчанию).
	 * @param array $tags - массив тегов, которыми помечается текущий запрос.
	 * @param integer $expire - время жизни запроса в кеше в секундах (если числовое значение), или timestamp смерти запроса в кеше (если UNIX_TIMESTAMP). По умолчанию равно параметру cache::$CI->config->item('cache')['memcache_expriration'].
	 * @param integer $error_behavior - поведение при неудачном выполнении запроса:<br>
	 * 0 - класс остановит выполнение скрипта, выбросив сообщение об ошибке, возвращённое сервером MySQL и текст запроса, вызвавшего ошибку;<br>
	 * 1 - класс вернёт false;<br>
	 * 2 - поведение будет взято из параметра cache::$CI->config->item('cache')['QUERY_ERROR_BEHAVIOR'];
	 * @return multitype: результат выборки в виде ассоциативного многомерного массива (в случае удачного выполнения запроса).
	 */
	
	public static function select ($query,$ignore_cache=FALSE,$tags=array(),$expire=0,$error_behavior=2){
		
		if ($error_behavior==2) $error_behavior=cache::$CI->config->item('cache')['QUERY_ERROR_BEHAVIOR'];
		switch (cache::$CI->config->item('cache')['MYSQLCACHE']) {
			case 1:
				$query=preg_replace('~^(?<!select)(select)~i','select sql_cache',str_ireplace('sql_no_cache', '', str_ireplace('sql_cache', '', $query)));//Заменять нужно только первый SELECT
			break;
			case 2:
				$query=preg_replace('~^(?<!select)(select)~i','select sql_no_cache',str_ireplace('sql_no_cache', '', str_ireplace('sql_cache', '', $query)));
			break;
			default:
			break;
		}
		if (!$ignore_cache && cache::$CI->config->item('cache')['MEMCACHE']==1 && cache::init()){
			$key=cache::qhash($query);
			$cache=cache::get($key);
			if ($cache===FALSE){//Кеш может вернуть пустое значение, потому нужно сравнение с учётом типа
				$cache=array();
				$result=cache::sql_query($query);// or die (mysql_error()." on query ".$query);
				if (!$result) if ($error_behavior==1) return (false); else die (mysqli_error(cache::$sql_connection)." on query ".$query);
				$rows = mysqli_num_rows($result);
				if (is_object($result)){
					for ($i=0;$i<$rows;$i++) $cache[$i] = mysqli_fetch_assoc($result);
					cache::set($key, $cache, $tags);
				}
			}
		} else {
			$cache=array();
			$result=cache::sql_query($query);// or die (mysql_error()." on query ".$query);
			if (!$result) if ($error_behavior==1) return (false); else die (mysqli_error(cache::$sql_connection)." on query ".$query);
			$rows = mysqli_num_rows($result);
			if (is_object($result)) for ($i=0;$i<$rows;$i++) {
				$cache[$i] = mysqli_fetch_assoc($result);
			}
		}
		return ($cache);
	}

	/**
	 * Выполняет вставку в БД, сбрасывая затронутые значения кеша.
	 * @param string $query - INSERT/UPDATE/DELETE запрос
	 * @param array $tags - массив тегов, для которых необходимо сбрасывать кешированные запросы 
	 * @param integer $error_behavior - поведение при неудачном выполнении запроса:<br>
	 * 0 - класс остановит выполнение скрипта, выбросив сообщение об ошибке, возвращённое сервером MySQL и текст запроса, вызвавшего ошибку;<br>
	 * 1 - класс вернёт false;<br>
	 * 2 - поведение будет взято из параметра cache::$CI->config->item('cache')['QUERY_ERROR_BEHAVIOR'];
	 * @return number - результат, соответствующий mysqli_insert_id() (в случае удачного выполнения запроса).
	 */
	
	public static function update ($query,$tags=array(),$error_behavior=2) {
		$result=cache::sql_query($query);
		if ($result){
			
			if ($error_behavior==2) $error_behavior=cache::$CI->config->item('cache')['QUERY_ERROR_BEHAVIOR'];
			switch (cache::$CI->config->item('cache') ['memcache_update']){
				case 1://Сбрасываем кеш по тегу
					foreach ($tags as $tag){
						$tag_keys=cache::get($tag);//Получаем все ключи с этим тегом
						if (!$tag_keys) break;
						foreach ($tag_keys as $tag_key) cache::delete(cache::qhash($tag_key));//Удаляем все записи с этими ключами
						cache::set(cache::qhash($tag),array());//Очищаем список тегированных записей
					}
				break;
				case 2://Сбрасываем весь кеш
					cache::flush();
				break;
				default://Не трогаем кеш
					;
				break;
			}
			return (mysqli_insert_id());
		} else {
			if ($error_behavior==1) die (mysqli_error()." on query ".$query); else return (false);
		}
	}
	
	/**
	 * Экранирует говно
	 */
	public function escape($inp){
		if(is_string($inp) && strlen($inp)>0) {
			return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp);
		}
	}
	
}

?>