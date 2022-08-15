<?php
/**
 * Copyright (C) 2022  Jaap Jansma (jaap.jansma@civicoop.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace JvH\PackagingSlipGeneratorBundle\Controller;

use Contao\CoreBundle\Controller\AbstractController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\System;
use Isotope\Isotope;
use Isotope\Model\Config;
use Isotope\Model\Product;
use Isotope\Model\Shipping;
use JvH\PackagingSlipGeneratorBundle\Form\Type\ShippingType;
use Krabo\IsotopePackagingSlipBundle\Model\IsotopePackagingSlipModel;
use Krabo\IsotopePackagingSlipBundle\Model\IsotopePackagingSlipProductCollectionModel;
use Krabo\IsotopeStockBundle\Form\Type\AccountType;
use Krabo\IsotopeStockBundle\Form\Type\ProductSkuQuantityType;
use Krabo\IsotopeStockBundle\Helper\BookingHelper;
use Krabo\IsotopeStockBundle\Model\BookingLineModel;
use Krabo\IsotopeStockBundle\Model\BookingModel;
use Krabo\IsotopeStockBundle\Model\PeriodModel;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment as TwigEnvironment;

/**
 * @Route("/contao/tl_isotope_packaging_slip",
 *     name=GeneratorController::class,
 *     defaults={"_scope": "backend"}
 * )
 */
class GeneratorController extends AbstractController {

  /**
   * @var TwigEnvironment
   */
  private $twig;

  /**
   * @var ContaoCsrfTokenManager
   */
  private $tokenManager;

  /**
   * @var string
   */
  private $csrfTokenName;

  public function __construct(TwigEnvironment $twig, ContaoCsrfTokenManager $tokenManager) {
    $this->twig = $twig;
    $this->tokenManager = $tokenManager;
    $this->csrfTokenName = System::getContainer()->getParameter('contao.csrf_token_name');
  }


  public function __invoke(Request $request): Response
  {
    $GLOBALS['TL_JAVASCRIPT'][] = 'assets/jquery/js/jquery.min.js|static';
    $defaultData = [
      'count' => 100,
      'description' => 'Test van ' . date("Y-m-d H:i"),
    ];
    $formBuilder = $this->createFormBuilder($defaultData);

    $formBuilder->add('debit_account', AccountType::class, [
      'label' => 'Debit rekening (Bestelling)',
    ]);
    $formBuilder->add('credit_account', AccountType::class, [
      'label' => 'Credit rekening (Magazijn)',
    ]);

    $formBuilder->add('shipping_id', ShippingType::class, [
      'label' => 'Verzendmethode',
    ]);

    $formBuilder->add('product_ids', CollectionType::class, [
      'entry_type' => ProductSkuQuantityType::class,
      'label' => 'Producten',
      'allow_add' => true,
      'prototype' => true,
      'entry_options' => [
        'attr' => ['class' => 'product_id-box'],
      ],
    ]);

    $formBuilder->add('description', TextType::class, [
      'label' => 'Omschrijving',
      'attr' => [
        'class' => 'tl_text',
      ],
      'row_attr' => [
        'class' => 'w50 widget clr'
      ],
    ]);

    $formBuilder->add('count', TextType::class, [
      'label' => 'Aantal pakbonnen',
      'attr' => [
        'class' => 'tl_text',
      ],
      'row_attr' => [
        'class' => 'w50 widget clr'
      ],
    ]);
    $formBuilder->add('save', SubmitType::class, [
      'label' => 'Genereer pakbonnen',
      'attr' => [
        'class' => 'tl_submit',
      ]
    ]);
    $formBuilder->add('REQUEST_TOKEN', HiddenType::class, [
      'data' => $this->tokenManager->getToken($this->csrfTokenName)
    ]);

    $form = $formBuilder->getForm();
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
      $submittedData = $form->getData();
      $config = Isotope::getConfig();
      for($i = 0; $i < $submittedData['count']; $i++) {
        $prefix = $config->packagingSlipPrefix;
        if (empty($prefix)) {
          $prefix = $config->orderPrefix;
        }
        $packagingSlip = new IsotopePackagingSlipModel();
        $packagingSlip->tstamp = time();
        $packagingSlip->date = time();
        $packagingSlip->status = '0';
        $packagingSlip->check_availability = '1';

        $packagingSlip->firstname = 'Test '.$i;
        $packagingSlip->lastname = $submittedData['description'];
        $packagingSlip->email = 'test_'.$i.'@example.com';
        $packagingSlip->phone = '0123456789'.$i;
        $packagingSlip->housenumber = $i;
        $packagingSlip->street_1 = 'Grote straat';
        $packagingSlip->postal = '1234 AA';
        $packagingSlip->city = 'Middel of Nowhere';
        $packagingSlip->country = 'NL';
        $packagingSlip->notes = $submittedData['description'];
        $packagingSlip->shipping_id = $submittedData['shipping_id']->id;
        $packagingSlip->config_id = $config->id;
        $packagingSlip->debit_account = $submittedData['debit_account']->id;
        $packagingSlip->credit_account = $submittedData['credit_account']->id;
        $shippingMethod = Shipping::findByPk($submittedData['shipping_id']->id);
        if ($shippingMethod->isotopestock_override_store_account) {
          $packagingSlip->credit_account = $shippingMethod->isotopestock_store_account;
        }
        if ($shippingMethod->shipper_id) {
          $packagingSlip->shipper_id = $shippingMethod->shipper_id;
        }
        $packagingSlip->save();
        $orderDigits = (int) $config->orderDigits;
        $packagingSlip->generateDocumentNumber($prefix, $orderDigits);
        $arrProducts = [];
        $timestamp = time();
        foreach($submittedData['product_ids'] as $product_id) {
          $objProduct = Product::findOneBy('sku', $product_id['sku']);
          if ($objProduct) {
            $product = new IsotopePackagingSlipProductCollectionModel();
            $product->pid = $packagingSlip->pid;
            $product->product_id = $objProduct->id;
            $product->quantity = $product_id['quantity'];
            $product->document_number = 'test_'.$timestamp.'_'.$i;
            $arrProducts[] = $product;
          }
        }
        IsotopePackagingSlipProductCollectionModel::saveProducts($packagingSlip, $arrProducts);
      }
    }

    $viewData['form'] = $form->createView();
    return new Response($this->twig->render('@PackagingSlipGenerator/generate.html.twig', $viewData));
  }

}