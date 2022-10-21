<?php

namespace Plugin\StripeRec\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Eccube\Entity\Block;
use Eccube\Entity\BlockPosition;
use Eccube\Entity\Page;
use Eccube\Entity\PageLayout;
use Eccube\Entity\Layout;
use Eccube\Repository\PageRepository;


class PageInitCommand extends Command {
    protected static $defaultName = "striperec:page:init";

    protected $container;
    protected $entityManager;

    const PAGES = array(
        [
            'name' =>  'mypage_stripe_rec',
            'label' =>  'MYページ/定期コース',
            'template'  =>  'StripeRec/Resource/template/default/Mypage/recurring_tab'
        ],
        [
            'name'  =>  'mypage_stripe_cancel_confirm',
            'label' =>  'MYページ/定期コースキャンセル',
            'template'  =>  'StripeRec/Resource/template/default/Mypage/recurring_cancel_confirm'
        ],
        [
            'name'  =>  'mypage_stripe_schedule',
            'label' =>  'MYページ/定期コーススケジュール',
            'template'  =>  'StripeRec/Resource/template/default/Mypage/schedule_tab'
        ],
        [
            'name'  =>  'plugin_striperec_update_method',
            'label' =>  '支払い方法変更',
            'template'  =>  'StripeRec/Resource/template/default/Shopping/collect_method'
        ],
        [
            'name'  =>  'plugin_striperec_checkout_page',
            'label' =>  '支払い画面',
            'template'  =>  'StripeRec/Resource/template/default/Shopping/checkout'
        ],
        
    );

    public function __construct(
        ContainerInterface $container
    )
    {
        $this->container = $container;
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        foreach(self::PAGES as $page_data){
            $url = $page_data['name'];
            $page = $this->entityManager->getRepository(Page::class)->findOneBy(compact('url'));
            if(!\is_null($page)){
                continue;
            }
            $page = new Page;
            $page->setName($page_data['label']);
            $page->setUrl($url);
            $page->setMetaRobots('noindex');
            $page->setFileName($page_data['template']);
            $page->setEditType(Page::EDIT_TYPE_DEFAULT);

            $this->entityManager->persist($page);
            $this->entityManager->flush();
            // $this->entityManager->commit();
            
            $pageLayoutRepository = $this->entityManager->getRepository(PageLayout::class);
            $pageLayout = $pageLayoutRepository->findOneBy([
                'page_id' => $page->getId()
            ]);
            // 存在しない場合は新規作成
            if (is_null($pageLayout)) {
                $pageLayout = new PageLayout;
                // 存在するレコードで一番大きいソート番号を取得
                $lastSortNo = $pageLayoutRepository->findOneBy([], ['sort_no' => 'desc'])->getSortNo();
                // ソート番号は新規作成時のみ設定
                $pageLayout->setSortNo($lastSortNo + 1);
            }
            // 下層ページ用レイアウトを取得
            $layout = $this->entityManager->getRepository(Layout::class)->find(Layout::DEFAULT_LAYOUT_UNDERLAYER_PAGE);

            $pageLayout->setPage($page);
            $pageLayout->setPageId($page->getId());
            $pageLayout->setLayout($layout);
            $pageLayout->setLayoutId($layout->getId());

            $this->entityManager->persist($pageLayout);
            $this->entityManager->flush();
            $output->write("insert page : $url\n");
        }
        $output->write("page insertion completed");
    }

}