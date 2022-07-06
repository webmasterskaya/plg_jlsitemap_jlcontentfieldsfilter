<?php

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Registry\Registry;
use Joomla\CMS\Uri\Uri;

class plgJLSitemapJlcontentfieldsfilter extends CMSPlugin
{
	protected $autoloadLanguage = true;

	public function onGetUrls(&$urls, $config)
	{
		$categoryExcludeStates = array(
			0  => 'Не опубликована',
			-2 => 'В корзине',
			2  => 'В архиве'
		);

		$multilanguage = $config->get('multilanguage');

		$db    = Factory::getDbo();
		$query = $db->getQuery(true)
			->select(
				array(
					'cf.catid', 'c.title', 'c.published', 'c.access',
					'c.metadata',
					'c.language', 'MAX(a.modified) as modified', 'c.params',
					'cf.id', 'cf.filter', 'cf.meta_title', 'cf.meta_desc',
					'cf.meta_keywords', 'cf.publish'
				)
			)
			->from($db->quoteName('#__categories', 'c'))
			->join('LEFT', '#__content AS a ON a.catid = c.id')
			->join(
				'INNER',
				'#__jlcontentfieldsfilter_data AS cf ON cf.catid = c.id'
			)
			->where(
				$db->quoteName('c.extension') . ' = ' . $db->quote(
					'com_content'
				)
			)
			->order($db->escape('c.lft') . ' ' . $db->escape('asc'));

		if ($multilanguage)
		{
			$query->select('assoc.key as association')
				->join(
					'LEFT',
					'#__associations AS assoc ON assoc.id = c.id AND assoc.context = '
					.
					$db->quote('com_categories.item')
				);
		}

		$db->setQuery($query);
		$rows = $db->loadObjectList();

		$nullDate   = $db->getNullDate();
		$changefreq = $this->params->get(
			'filters_changefreq', $config->get('changefreq', 'weekly')
		);
		$priority   = $this->params->get(
			'filters_priority', $config->get('priority', '0.5')
		);
		$withTitle  = $config->get('only_with_title', '1');

		$filters    = array();
		$alternates = array();

		foreach ($rows as $row)
		{
			$loc = 'index.php?option=com_content&view=category&id='
				. $row->catid;

			if (!empty($row->language) && $row->language !== '*'
				&& $multilanguage)
			{
				$loc .= '&lang=' . $row->language;
			}

			$filterQuery = $this->parseFilterToQueryString($row->filter);

			$loc .= '&' . $filterQuery;

			$metadata = new Registry($row->metadata);
			$exclude  = array();
			if (preg_match(
				'/noindex/',
				$metadata->get('robots', $config->get('siteRobots'))
			))
			{
				$exclude[] = array(
					'type' => 'Менеджер материалов - Исключение категории',
					'msg'  => 'Robots noindex'
				);
			}

			if (isset($categoryExcludeStates[$row->published]))
			{
				$exclude[] = array(
					'type' => 'Менеджер материалов - Исключение категории',
					'msg'  => $categoryExcludeStates[$row->published]
				);
			}

			if ($row->publish != 1)
			{
				$exclude[] = array(
					'type' => 'Фильтр по полям - исключение фильтра',
					'msg'  => 'Не опубликован'
				);
			}

			if (!in_array($row->access, $config->get('guestAccess', array())))
			{
				$exclude[] = array(
					'type' => 'Менеджер материалов - Исключение категории',
					'msg'  => 'Права доступа'
				);
			}

			if ($withTitle && empty($row->meta_title))
			{
				$exclude[] = array(
					'type' => 'Фильтр по полям - исключение фильтра',
					'msg'  => 'Отсутсвует Title'
				);
			}

			$lastmod = (!empty($row->modified) && $row->modified != $nullDate)
				? $row->modified : false;

			$filter             = new stdClass();
			$filter->type       = 'Отфильтрованные материалы';
			$filter->title      = $row->meta_title ?: $row->title;
			$filter->loc        = $loc;
			$filter->changefreq = $changefreq;
			$filter->priority   = $priority;
			$filter->lastmod    = $lastmod;
			$filter->exclude    = (!empty($exclude)) ? $exclude : false;
			$filter->alternates = ($multilanguage && !empty($row->association))
				? $row->association : false;

			$filters[] = $filter;

			if ($multilanguage && !empty($row->association) && empty($exclude))
			{
				if (!isset($alternates[$row->association]))
				{
					$alternates[$row->association] = array();
				}

				$alternates[$row->association][$row->language] = $loc;
			}


		}

		// Add alternates to categories
		if (!empty($alternates))
		{
			foreach ($filters as &$filter)
			{
				$filter->alternates = ($filter->alternates)
					? $alternates[$filter->alternates] : false;
			}
		}

		$urls = array_merge($urls, $filters);

		return $urls;
	}

	private function parseFilterToQueryString($filter)
	{

		$filters = explode('&', $filter);

		$arQuery = [];

		foreach ($filters as $item)
		{
			list($filter_name, $filter_values) = explode('=', $item);

			$values = explode(',', $filter_values);

			foreach ($values as $value)
			{
				$arQuery[$filter_name][] = urlencode($value);
			}
		}

		$arQuery['is_filter'] = 1;

		$query = Uri::buildQuery(['jlcontentfieldsfilter' => $arQuery]);

		return $query;
	}
}