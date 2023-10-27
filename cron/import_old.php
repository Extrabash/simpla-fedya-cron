<?php

ini_set('max_execution_time', '6000');

error_reporting(-1);
ini_set('display_errors', 'On');


require_once dirname(__FILE__).'/../api/UPcms.php';

class ImportAjax extends UPcms
{
    private $url = 'https://mks.master.pro/openapi/catalog/a30a7b0083f8d74cba3de8a3a32d20cd';
    
    private $added_categories = array();
    
    private $counter = array(
        'product_added' => 0,
        'product_updated' => 0,
        'category_added' => 0,
        'category_updated' => 0,
    );
    
    protected $tempdir;
    protected $logdir = 'logs/';
    
    const LOGGING = 1;
    const TRUNCATE_TABLE = 0;
    const STOCK_NULLING = 0;
    
    public function __construct()
    {
    	parent::__construct();

        $this->tempdir = $this->config->root_dir.'cron/';
        $this->logdir = $this->config->root_dir.'cron/'.$this->logdir;
        
        if (self::TRUNCATE_TABLE)
            $this->truncate();
        
        
    }
    
    
    public function run()
    {
        if (self::STOCK_NULLING)
        {
            $query = $this->db->placehold("
                UPDATE __variants
                SET stock = 0
            ");
            $this->db->query($query);
        }
        
    	$this->log(PHP_EOL.date('d-m-Y H:i:s'));
    	$this->log('START '.__CLASS__);
        
        $temp_filename = $this->tempdir.'import.xml';
        
        if ($this->load_file($this->url, $temp_filename))
        {
            $this->log('upload');
                        
            $doc = new DOMDocument;
            $reader = new XMLReader();
            $reader->open($this->tempdir . 'import.xml');

            while ($reader->read())
            {
                if ($reader->name == 'category' && XMLReader::ELEMENT == $reader->nodeType)
                {
                    $node = simplexml_import_dom($doc->importNode($reader->expand(), true));
                    
                    $parent_id = (string)$node['parentId'];
                    $item = array(
                        'id' => (string)$node['id'],
                        'name' => (string)$node,
                        'parent_id' => empty($parent_id) ? 0 : (int)$parent_id
                    );
//echo '<hr />'.__FILE__.':'.__LINE__.'<pre>'; var_dump($item); echo '</pre>';
                    $this->import_category($item);

                }

                if ($reader->name == 'offer' && XMLReader::ELEMENT == $reader->nodeType)
                {
                    $node = simplexml_import_dom($doc->importNode($reader->expand(), true));
                    
                    $item = array(
                        'id' => (string)$node['id'],
                        'url' => (string)$node->url,
                        'price' => (string)$node->price,
                        'description' => (string)$node->description,
                        'image' => (string)$node->picture,
                        'name' => (string)$node->name,
                        'category_id' => (string)$node->categoryId,
                        'sku' => (string)$node->vendorCode,
                        'stock' => (string)$node->count,
                    );

//echo '<hr />'.__FILE__.':'.__LINE__.'<pre>'; var_dump($item); echo '</pre>';

                    $this->import_item($item);

                }
            }
            
            $reader->close();
            unset($reader);
        
            $this->report();
        }
    
    }
        
    private function truncate()
    {
        $this->db->query("TRUNCATE TABLE __categories");
        $this->db->query("TRUNCATE TABLE __products");
        $this->db->query("TRUNCATE TABLE __variants");
        $this->db->query("TRUNCATE TABLE __products_categories");
        $this->db->query("TRUNCATE TABLE __images");
    }
    
    private function import_category($item)
    {        
        $item_id = (int)$item['id'];
        if (!empty($item_id))
        {
            $query = $this->db->placehold("
                SELECT id 
                FROM __categories 
                WHERE external_id = ?
            ", $item['id']);
            $this->db->query($query);

            if (!($category_id = $this->db->result('id')))
            {
                $add_category = array(
                    'name' => $item['name'],
                    'meta_title' => $item['name'],
                    'url' => $this->translit($item['name']),
                    'external_id' => $item['id'],
                    'visible' => 1,
                );
                $category_id = $this->categories->add_category($add_category);
                
                $this->counter['category_added']++;
            }
            else
            {
                $this->counter['category_updated']++;
            }

            // родительская категория
            $this->db->query("
                SELECT id 
                FROM __categories 
                WHERE external_id = ?
            ", $item['parent_id']);
            $parent_id = $this->db->result('id');
            $this->categories->update_category($category_id, array(
                'parent_id' => $parent_id
            ));
            
            $this->added_categories[$item['id']] = $category_id;
        }
    }

    // Импорт одного товара $item[column_name] = value;
	private function import_item($item)
	{
        if (empty($item['id']))
            return;

//echo __FILE__.' '.__LINE__.'<br /><pre>';var_dump($item);echo '</pre><hr />';        

        // ищем или создаем продукт
        $query = $this->db->placehold("
            SELECT id 
            FROM __products 
            WHERE external_id = ?
        ", $item['id']);
        $this->db->query($query);
        
        if (!($product_id = $this->db->result('id')))
        {
            $add_product = array(
                'name' => $item['name'],
                'meta_title' => $item['name'],
                'url' => $this->translit($item['name']),
                'body' => $item['description'],
                'visible' => 1,
                'external_id' => $item['id'],
            );
            $product_id = $this->products->add_product($add_product);
        
            $this->counter['product_added']++;
        }
        else
        {
            $this->counter['product_updated']++;
        }
        
        // ишем или создаем вариант
        $query = $this->db->placehold("
            SELECT id 
            FROM __variants 
            WHERE product_id = ?
        ", $product_id);
        $this->db->query($query);
        
        if ($variant_id = $this->db->result('id'))
        {
            $this->variants->update_variant($variant_id, array(
                'price' => $item['price'],
                'stock' => empty($item['stock']) ? 0 : NULL
            ));
        }
        else
        {
            $variant_id = $this->variants->add_variant(array(
	            'sku' => $item['sku'],
                'price' => $item['price'],
                'product_id' => $product_id,
                'stock' => $item['stock'],
            ));
        }
        
        // категории
        $query = $this->db->placehold("
            SELECT id 
            FROM __categories 
            WHERE external_id = ?
        ", $item['category_id']);
        $this->db->query($query);
        
        if ($category_id = $this->db->result('id'))
        {
            $this->categories->add_product_category($product_id, $category_id);
	    }
        
        // изображения
        if (!empty($item['image']))
        {
            $pathinfo = pathinfo($item['image']);
            
            $expl = explode('/', $pathinfo['dirname']);
            $last_elem = array_shift($expl);
            $filename = $last_elem.'_'.$pathinfo['basename'];
            
            $local_filename = $this->config->root_dir.$this->config->original_images_dir.$filename;
            if (!file_exists($local_filename))
            {
                $this->load_file($item['image'], $local_filename);
            }
            
            $query = $this->db->placehold("
                SELECT id 
                FROM __images 
                WHERE product_id = ?
                AND filename = ?
            ", $product_id, $filename);
            $this->db->query($query);
            
            if (!($image_id = $this->db->result('id')))
            {
                $this->products->add_image($product_id, $filename);
            }
//echo __FILE__.' '.__LINE__.'<br /><pre>';var_dump($pathinfo);echo '</pre><hr />';
        }
    }

    protected function log($message)
    {
        if (self::LOGGING)
        {
            $maxlogsize = 1024*1024;
            $logfile = $this->logdir.'log.htm';
            
            if (filesize($logfile) > $maxlogsize)
            {
                $new_logfile = $this->logdir.date('YmdHis').'.htm';
                rename ($logfile, $new_logfile);
                file_put_contents($logfile, '');
            }
            file_put_contents($logfile, $message.PHP_EOL, FILE_APPEND);
        }
    }
    
    protected function get_file($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        
        $data = curl_exec($ch);

        curl_close($ch);

        return $data;
    }
    
    protected function load_file($url, $filename)
    {
        
        $curl = curl_init();
        $file = fopen($filename, 'w');
        curl_setopt($curl, CURLOPT_URL, $url); #input
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FILE, $file); #output
        $result = curl_exec($curl);
        curl_close($curl);
        fclose($file);  
        
        return $result;      
    }
    
   	protected function translit($text)
	{
		$ru = explode('-', "А-а-Б-б-В-в-Ґ-ґ-Г-г-Д-д-Е-е-Ё-ё-Є-є-Ж-ж-З-з-И-и-І-і-Ї-ї-Й-й-К-к-Л-л-М-м-Н-н-О-о-П-п-Р-р-С-с-Т-т-У-у-Ф-ф-Х-х-Ц-ц-Ч-ч-Ш-ш-Щ-щ-Ъ-ъ-Ы-ы-Ь-ь-Э-э-Ю-ю-Я-я"); 
		$en = explode('-', "A-a-B-b-V-v-G-g-G-g-D-d-E-e-E-e-E-e-ZH-zh-Z-z-I-i-I-i-I-i-J-j-K-k-L-l-M-m-N-n-O-o-P-p-R-r-S-s-T-t-U-u-F-f-H-h-TS-ts-CH-ch-SH-sh-SCH-sch---Y-y---E-e-YU-yu-YA-ya");

	 	$res = str_replace($ru, $en, $text);
		$res = preg_replace("/[\s]+/ui", '-', $res);
		$res = preg_replace('/[^\p{L}\p{Nd}\d-]/ui', '', $res);
	 	$res = strtolower($res);
	    return $res;  
	}

    private function report()
    {
        $this->log('Категорий добавлено: '.$this->counter['category_added']);
        $this->log('Категорий обновлено: '.$this->counter['category_updated']);
        $this->log('Продуктов добавлено: '.$this->counter['product_added']);
        $this->log('Продуктов обновлено: '.$this->counter['product_updated']);
        
        echo '<html>';
        echo '<head>';
        echo '<title>Импорт</title>';
        echo '</head>';
        echo '<body>';
        echo '<h2>Импорт завершен</h2>';
        echo '<ul>';
        echo '<li style="color:green">Категорий добавлено: '.$this->counter['category_added'].'</li>';
        echo '<li style="color:blue">Категорий обновлено: '.$this->counter['category_updated'].'</li>';
        echo '<li style="color:green">Продуктов добавлено: '.$this->counter['product_added'].'</li>';
        echo '<li style="color:blue">Продуктов обновлено: '.$this->counter['product_updated'].'</li>';
        echo '</ul>';
        echo '</body>';
        echo '</html>';
        
    }
    
}

$ajax = new ImportAjax();
$ajax->run();