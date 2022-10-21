<?php

namespace Plugin\StripeRec\Service\Admin;

use Eccube\Service\CsvExportService as ParentService;
use Eccube\Util\FormUtil;
use Plugin\StripeRec\Entity\StripeRecOrder;
use Plugin\StripeRec\Entity\StripeRecOrderItem;
use Plugin\StripeRec\Entity\RecCsv;
use Plugin\StripeRec\Form\Type\Admin\StripeRecSearchType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class CsvExportService extends ParentService {
    
    protected $container;

    public function setContainer(ContainerInterface $container)
    {
        $this->container = $container;
        return $this;
    }
    
    public function getOrderQueryBuilder(Request $request)
    {
        $session = $request->getSession();
        $builder = $this->formFactory
            ->createBuilder(StripeRecSearchType::class);
        $searchForm = $builder->getForm();

        $viewData = $session->get('plugin.striperec.order.search', []);
        $searchData = FormUtil::submitAndGetData($searchForm, $viewData);

        $rec_order_repo = $this->entityManager->getRepository(StripeRecOrder::class);
        // 受注データのクエリビルダを構築.
        $qb = $rec_order_repo
            ->getQueryBuilderBySearchDataForAdmin($searchData);
        return $qb;
    }
    public function exportHeader()
    {
        $rec_csv_repo = $this->entityManager->getRepository(RecCsv::class);
        $csvs = $rec_csv_repo->getAll();

        $row = [];
        foreach ($csvs as $field) {
            $row[] = $field->getLabel();
        }
        $this->fopen();
        $this->fputcsv($row);
        $this->fclose();
    }

    public function getRecData($rec_order, $Order, $OrderItem, $initial_amount, $recurring_amount) {
        $row = [];
        $rec_csv_repo = $this->entityManager->getRepository(RecCsv::class);

        $csvs = $rec_csv_repo->getAll();
        foreach($csvs as $field_data) {
            switch($field_data->getType()) {
                case "const":
                    $row[] = $field_data->getValue();
                    break;
                case "field":
                    $Shipping = $OrderItem->getShipping();
                    $method_string = $field_data->getField();
                    
                    $entity = $field_data->getEntity();
                    $entity = $$entity;
                    $row[] = $this->getField($method_string, $entity);
                    break;
                case "parameter":
                    $parameter = $field_data->getField();
                    $row[] = $$parameter;
                    break;
                case "service":
                    if (empty($this->container)) break;
                    $field = $field_data->getField();
                    $segs = \explode("/", $field);
                    if (count($segs) < 2) break;
                    $service = $segs[0];
                    $method = $segs[1];
                    $row[] = $this->container->get($service)->$method(compact('rec_order', 'Order', 'OrderItem', 'initial_amount', 'recurring_amount'));
                    break;
            }
        }
        return $row;
    }

    private function getField($method_string, $entity)
    {
        $methods = explode("/", $method_string);
        
        $res = $entity;
        if (!$res) return null;
        foreach($methods as $key => $method) 
        {
            if ($method == "Collection") {
                $method = "get" . $methods[$key + 1];
                $res_ids = [];
                foreach($res as $item) {
                    $res_ids[] = $item->$method();
                }
                // die(\implode("|", $res_ids));
                return \implode("|", $res_ids);
            }
            $method = "get" . $method;
            $res = $res->$method();
            
            if (\is_null($res)) return null;
        }
        if (\is_scalar($res)) return $res;
        if ($res instanceof \DateTime) {
            return $res->format("Y-m-d H:i:s");
        }
    }
}