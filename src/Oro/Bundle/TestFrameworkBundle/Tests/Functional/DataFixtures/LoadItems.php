<?php

namespace Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\Persistence\ObjectManager;

use Oro\Bundle\TestFrameworkBundle\Entity\Item;

class LoadItems extends AbstractFixture
{
    const ITEM1 = 'test-item1';

    /**
     * @var array
     */
    protected $items = [
        self::ITEM1 => [
            'stringValue' => 'value1',
        ],
    ];

    /**
     * {@inheritdoc}
     */
    public function load(ObjectManager $manager)
    {
        foreach ($this->items as $name => $data) {
            $item = $this->createItem($data);

            $this->addReference($name, $item);
            $manager->persist($item);
        }

        $manager->flush();
    }

    /**
     * @param array $data
     * @return Item
     */
    protected function createItem(array $data)
    {
        $item = new Item();

        foreach ($data as $key => $value) {
            $item->$key = $value;
        }

        return $item;
    }
}
