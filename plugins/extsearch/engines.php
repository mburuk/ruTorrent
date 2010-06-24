<?php

require_once( dirname(__FILE__)."/../../php/util.php" );
require_once( $rootPath.'/php/cache.php');
require_once( $rootPath.'/php/settings.php');
require_once( $rootPath.'/php/Snoopy.class.inc');
eval( getPluginConf( 'extsearch' ) );

class commonEngine
{
	public $defaults = array( "public"=>true, "page_size"=>100 );
	public $categories = array( 'All'=>'' );

	public function action($what,$cat,$arr,$limit,$useGlobalCats)
	{
	}
	public function getSource()
	{
		$className = get_class($this);
		$pos = strpos($className, "Engine");
		if($pos!==false)
			$className = substr($className,0,$pos);
		return($className);
	}
	public function getNewEntry()
	{
		return( array(
			"time"=>0, 
			"cat"=>'', 
			"size"=>0,
			"desc"=>'',
			"name"=>'',
			"src"=>$this->getSource(),
			"seeds"=>0,
			"peers"=>0,
			));
	}
	public function makeClient($url)
	{
		$client = new Snoopy();
		$client->agent = HTTP_USER_AGENT;
		$client->read_timeout = 5;
		$client->use_gzip = HTTP_USE_GZIP;
		return($client);
	}
	public function fetch($url,$encode = true)
	{
		$client = $this->makeClient($url);
		if($encode)
			$url = Snoopy::linkencode($url);
		@$client->fetch($url);
		if($client->status>=200 && $client->status<300)
		{
			ini_set( "pcre.backtrack_limit", max(strlen($client->results),100000) );
			return($client);
		}
		return(false);
	}
	public function getTorrent( $url )
	{
		$cli = $this->fetch( $url );
		if($cli)
		{
			$name = $cli->get_filename();
			if($name===false)
				$name = md5($url).".torrent";
			$name = getUniqueFilename(getUploadsPath()."/".$name);
			$f = @fopen($name,"w");
			if($f!==false)
			{
				@fwrite($f,$cli->results,strlen($cli->results));
				fclose($f);
				@chmod($name,0666);
				return($name);
			}
		}
		return(false);
	}
	static public function removeTags($s, $charset = "UTF-8")
	{
		return(html_entity_decode( str_replace("&nbsp;"," ",strip_tags($s)), ENT_QUOTES, $charset ));
	}
	static public function formatSize( $item )
	{
		$sz = explode(" ",self::removeTags($item));
		if(count($sz)>1)
		{
			$val = floatval($sz[0]);
			switch($sz[1])
			{
				case "TiB":
				case "TB":
					$val*=1024;
				case "GiB":
				case "GB":
					$val*=1024;
				case "MiB":
				case "MB":
					$val*=1024;	
				case "KiB":
				case "KB":
					$val*=1024;	
			}
			return($val);
		}
		return(0);
	}
}

class rSearchHistory
{
	public $hash = "extsearch_history.dat";
	public $lst = array();
	public $changed = false;

	public function add( $url, $hash )
	{
		$this->lst[$url] = array( "hash"=>$hash, "time"=>time() );
		$this->changed = true;
	}
	public function del( $href )
	{
		if(array_key_exists($href,$this->lst))
		{
			unset($this->lst[$href]);
			$this->changed = true;
		}
	}
	public function isChanged()
	{
		return($this->changed);
	}
	public function getHash( $url )
	{
		if(array_key_exists($url,$this->lst))
			return($this->lst[$url]["hash"]);
		return("");
	}
	public function isOverflow()
	{
		global $searchHistoryMaxCount;
		return( count($this->lst) > $searchHistoryMaxCount );
	}
	public function pack()
	{
		uasort($this->lst, create_function( '$a,$b', 'return( ($a["time"] > $b["time"]) ? 1 : (($a["time"] < $b["time"]) ? -1 : 0) );'));
		$cnt = count($this->lst)/2;
		$i=0;
		foreach( $this->lst as $key=>$value )
		{
			unset($this->lst[$key]);
			if(++$i>=$cnt)
				break;
		}
	}
}

class engineManager
{
	public $hash = "extsearch.dat";
	public $limit = 1000;
	public $engines = array();

	static public function load()
	{
		$cache = new rCache();
		$ar = new engineManager();
		return($cache->get($ar) ? $ar : false);
	}

	public function store()
	{
		$cache = new rCache();
		return($cache->set($this));
	}

	public function obtain( $dir = '../plugins/extsearch/engines' )
	{
		$oldEngines = $this->engines;
		$this->engines = array();
		if( $handle = opendir($dir) )
		{
			while(false !== ($file = readdir($handle)))
			{
				if(is_file($dir.'/'.$file))
				{
					$name = basename($file,".php");
					$this->engines[$name] = array( "name"=>$name, "path"=>fullpath($dir.'/'.$file), "object"=>$name."Engine", "enabled"=>true, "global"=>true, "limit"=>100 );
					$obj = $this->getObject($name);
					$this->engines[$name]["enabled"] = $obj->defaults["public"];
					$this->engines[$name]["limit"] = $obj->defaults["page_size"];
					if(array_key_exists("disabled",$obj->defaults) && $obj->defaults["disabled"])
						$this->engines[$name]["enabled"] = false;
					if(array_key_exists($name,$oldEngines) && array_key_exists("limit",$oldEngines[$name]))
					{
						$this->engines[$name]["enabled"] = $oldEngines[$name]["enabled"];
						$this->engines[$name]["global"] = $oldEngines[$name]["global"];
						$this->engines[$name]["limit"] = $oldEngines[$name]["limit"];
					}
				}
			} 
			closedir($handle);		
	        }
		$this->store();
	}

	public function get()
	{
                $ret = "theSearchEngines.globalLimit = ".$this->limit."; theSearchEngines.sites = {";
		foreach( $this->engines as $name=>$nfo )
		{
			$ret.="'".$name."': { enabled: ".intval($nfo["enabled"]). ", global: ".intval($nfo["global"]).", limit: ".$nfo["limit"].", cats: [";
			if($nfo["enabled"])
			{
				$obj = $this->getObject($name);
				foreach( $obj->categories as $cat=>$prm )
				{
					$ret.=quoteAndDeslashEachItem($cat);
					$ret.=',';
				}
				$len = strlen($ret);
				if($ret[$len-1]==',')
					$ret = substr($ret,0,$len-1);
			}
			$ret.=']},';
		}
		$len = strlen($ret);
		if($ret[$len-1]==',')
			$ret = substr($ret,0,$len-1);
		return($ret."};\n");
	}

	public function set()
	{
		foreach( $this->engines as $name=>$nfo )
		{
			if(isset($_REQUEST[$name."_enabled"]))
				$this->engines[$name]["enabled"] = $_REQUEST[$name."_enabled"];
			if(isset($_REQUEST[$name."_global"]))
				$this->engines[$name]["global"] = $_REQUEST[$name."_global"];
			if(isset($_REQUEST[$name."_limit"]))
				$this->engines[$name]["limit"] = intval($_REQUEST[$name."_limit"]);
		}
		if(isset($_REQUEST["limit"]))
			$this->limit = intval($_REQUEST["limit"]);
		$this->store();
	}

	static public function loadHistory( $withRSS = false )
	{
		$cache = new rCache();
		$history = new rSearchHistory();
		$cache->get($history);
		if($withRSS)
		{
			$theSettings = rTorrentSettings::load();
			if($theSettings->isPluginRegistered("rss"))
			{
				global $rootPath;
				require_once( $rootPath.'/plugins/rss/rss.php');
				$cache  = new rCache( '/rss/cache' );
				$rssHistory = new rRSSHistory();
				if($cache->get($rssHistory))
				{
					foreach($rssHistory->lst as $url=>$hash)
					{
						if(strlen($hash)==40)
							$history->add($url,$hash);
					}
				}
			}
		}
		return($history);
	}

	static public function saveHistory( $history )
	{
		if($history->isChanged())
		{
			if($history->isOverflow())
				$history->pack();
			$cache = new rCache();
			return($cache->set($history));
		}
		return(true);
	}

	public function getObject( $eng )
	{
		if(array_key_exists($eng,$this->engines))
		{
			$nfo = $this->engines[$eng];
			require_once( $nfo["path"] );
			$object = new $nfo["object"]();
		}
		else
			$object = new commonEngine();
		return($object);
	}

	static protected function correctItem(&$nfo)
	{
		if(empty($nfo["time"]))
			$nfo["time"] = 0;
		if(empty($nfo["size"]))
			$nfo["time"] = 0;
		if(empty($nfo["seeds"]))
			$nfo["seeds"] = 0;
		if(empty($nfo["peers"]))
			$nfo["peers"] = 0;
	}

	public function action( $eng, $what, $cat = "all" )
	{
		$arr = array();
		$what = urlencode($what);
		if($eng=="all")
		{
			foreach( $this->engines as $name=>$nfo )
			{
				if($nfo["global"] && $nfo["enabled"])
				{
					require_once( $nfo["path"] );
					$object = new $nfo["object"]();
					$object->action($what,$cat,$arr,$nfo["limit"],true);
				}
			}
		}
		else
		{
			$object = $this->getObject($eng);
			$object->action($what,$cat,$arr,$this->limit,false);
		}
		uasort($arr, create_function( '$a,$b', 'return( (intval($a["seeds"]) > intval($b["seeds"])) ? -1 : ((intval($a["seeds"]) < intval($b["seeds"])) ? 1 : 0) );'));
		$cnt = 0;		
		$history = self::loadHistory(true);
                $ret = '{eng: '.quoteAndDeslashEachItem($eng).', cat: '.quoteAndDeslashEachItem($cat).', data: [';
		foreach( $arr as $href=>$nfo )
		{
			$hash = $history->getHash( $href );
			self::correctItem($nfo);
			$item = "{ time: ".$nfo["time"].", cat: ".quoteAndDeslashEachItem($nfo["cat"]).", size: ".$nfo["size"].
				", name: ".quoteAndDeslashEachItem($nfo["name"]).", desc: ".quoteAndDeslashEachItem($nfo["desc"]).
				", src: ".quoteAndDeslashEachItem($nfo["src"]).", link: ".quoteAndDeslashEachItem($href).
				", hash: ".quoteAndDeslashEachItem($hash).
				", seeds: ".$nfo["seeds"].", peers: ".$nfo["peers"]." },";
			$ret.=$item;
			$cnt++;
			if($cnt>=$this->limit)
				break;
		}
		$len = strlen($ret);
		if($ret[$len-1]==',')
			$ret = substr($ret,0,$len-1);
		return($ret.']}');
	}

	public function getTorrents( $engs, $urls, $isStart, $isAddPath, $directory, $label, $fast )
	{
		$ret = array();
		$history = self::loadHistory();
		for( $i=0; $i<count($urls); $i++ )
		{
			$url = $urls[$i];
			$success = false;
			if(strpos($url,"magnet:")===0)
				$success = rTorrent::sendMagnet($url, $isStart, $isAddPath, $directory, $label);
			else
			{
				$object = $this->getObject($engs[$i]);
        			$torrent = $object->getTorrent( $url, $object );
				if($torrent!==false)
				{	
					global $saveUploadedTorrents;
					if(($success = rTorrent::sendTorrent($torrent, $isStart, $isAddPath, $directory, $label, $saveUploadedTorrents, $fast))===false)
						@unlink($torrent);
					else
						$history->add($url,$success);
				}
			}
			$ret[] = $success;
		}
		self::saveHistory($history);
		return($ret);
	}
}

?>