<?php

declare(strict_types=1);


/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright 2017
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Circles\Db;


use daita\MySmallPhpTools\Db\Nextcloud\nc22\NC22ExtendedQueryBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use OC;
use OCA\Circles\IFederatedModel;
use OCA\Circles\IFederatedUser;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Federated\RemoteInstance;
use OCA\Circles\Model\Member;
use OCA\Circles\Service\ConfigService;
use OCP\DB\QueryBuilder\ICompositeExpression;

/**
 * Class CoreRequestBuilder
 *
 * @package OCA\Circles\Db
 */
class CoreRequestBuilder extends NC22ExtendedQueryBuilder {


	const PREFIX_MEMBER = 'member_';
	const PREFIX_OWNER = 'owner_';
	const PREFIX_INITIATOR = 'initiator_';
	const PREFIX_CIRCLE = 'circle_';
	const PREFIX_OWNER_BASED_ON = 'owner_based_on_';
	const PREFIX_INITIATOR_BASED_ON = 'initiator_based_on_';
	const PREFIX_BASED_ON = 'based_on_';


	static $IMPORT_CIRCLE = [
		'',
		self::PREFIX_MEMBER
	];

	static $IMPORT_BASED_ON = [
		'',
		self::PREFIX_MEMBER
	];

	static $IMPORT_OWNER = [
		'',
		self::PREFIX_CIRCLE
	];

	static $IMPORT_INITIATOR_BASED_ON = [
		self::PREFIX_INITIATOR
	];

	static $IMPORT_OWNER_BASED_ON = [
		self::PREFIX_OWNER
	];


	static $IMPORT_INITIATOR = [
		'',
		self::PREFIX_CIRCLE
	];


	/** @var ConfigService */
	private $configService;


	/**
	 * CoreRequestBuilder constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->configService = OC::$server->get(ConfigService::class);
	}


	/**
	 * @param IFederatedModel $federatedModel
	 *
	 * @return string
	 */
	public function getInstance(IFederatedModel $federatedModel): string {
		$instance = $federatedModel->getInstance();

		return ($this->configService->isLocalInstance($instance)) ? '' : $instance;
	}


	/**
	 * @param string $id
	 */
	public function limitToCircleId(string $id): void {
		$this->limitToDBField('circle_id', $id, true);
	}

	/**
	 * @param string $name
	 */
	public function limitToName(string $name): void {
		$this->limitToDBField('name', $name);
	}

	/**
	 * @param int $config
	 */
	public function limitToConfig(int $config): void {
		$this->limitToDBFieldInt('config', $config);
	}

	/**
	 * @param int $config
	 */
	public function limitToConfigFlag(int $config): void {
		$this->andWhere($this->expr()->bitwiseAnd($this->getDefaultSelectAlias() . '.config', $config));
	}


	/**
	 * @param string $singleId
	 */
	public function limitToSingleId(string $singleId): void {
		$this->limitToDBField('single_id', $singleId, true);
	}


	/**
	 * @param string $itemId
	 */
	public function limitToItemId(string $itemId): void {
		$this->limitToDBField('item_id', $itemId, true);
	}


	/**
	 * @param string $host
	 */
	public function limitToInstance(string $host): void {
		$this->limitToDBField('instance', $host, false);
	}


	/**
	 * @param int $userType
	 */
	public function limitToUserType(int $userType): void {
		$this->limitToDBFieldInt('user_type', $userType);
	}


	/**
	 * @param IFederatedUser $initiator
	 * @param string $alias
	 * @param bool $mustBeMember
	 * @param bool $canBeVisitor
	 */
	public function limitToInitiator(
		IFederatedUser $initiator,
		string $alias = '',
		bool $mustBeMember = false,
		bool $canBeVisitor = false
	): void {
		// TODO: Based on membership, not on USerID !!
		$this->leftJoinInitiator($initiator, 'init', $alias);
		$this->leftJoinMembership('mbs', 'init', $alias);
		$this->leftJoinBasedOnCircle(self::PREFIX_INITIATOR_BASED_ON);
		$this->limitVisibility('mbs', $alias, $mustBeMember, $canBeVisitor);
	}


	/**
	 * @param string $instance
	 * @param string $aliasCircle
	 * @param string $aliasOwner
	 * @param bool $sensitive
	 */
	public function limitToRemoteInstance(
		string $instance,
		bool $sensitive = true,
		string $aliasCircle = 'c',
		string $aliasOwner = 'o'
	): void {
		$this->leftJoinRemoteInstance($instance, 'ri');
		$this->leftJoinMemberFromInstance($instance, 'mi', $aliasCircle);
		$this->leftJoinMemberFromRemoteCircle($instance, 'rco', $aliasCircle);
		$this->limitRemoteVisibility($sensitive, 'ri', 'rco', $aliasCircle, $aliasOwner, 'mi');
	}


	/**
	 * @param Circle $circle
	 */
	public function filterCircle(Circle $circle): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		if ($circle->getDisplayName() !== '') {
			$this->searchInDBField('display_name', '%' . $circle->getDisplayName() . '%');
		}
	}


	/**
	 * @param Member $member
	 */
	public function limitToMembership(Member $member): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();

		$alias = 'm';
		$this->generateMemberSelectAlias($alias, self::PREFIX_MEMBER)
			 ->leftJoin(
				 $this->getDefaultSelectAlias(), CoreQueryBuilder::TABLE_MEMBER, $alias,
				 $expr->eq($alias . '.circle_id', $this->getDefaultSelectAlias() . '.unique_id')
			 );

		$this->filterMembership($member, $alias);
	}


	/**
	 * @param Member $member
	 * @param string $alias
	 */
	public function filterMembership(Member $member, string $alias = ''): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$alias = ($alias === '') ? $this->getDefaultSelectAlias() : $alias;
		$expr = $this->expr();
		$andX = $expr->andX();

		if ($member->getUserId() !== '') {
			$andX->add($expr->eq($alias . '.user_id', $this->createNamedParameter($member->getUserId())));
		}

		if ($member->getUserType() > 0) {
			$andX->add($expr->eq($alias . '.user_type', $this->createNamedParameter($member->getUserType())));
		}

		if ($member->getInstance() !== '') {
			$andX->add(
				$expr->eq($alias . '.instance', $this->createNamedParameter($this->getInstance($member)))
			);
		}

		if ($member->getLevel() > 0) {
			$andX->add($expr->gte($alias . '.level', $this->createNamedParameter($member->getLevel())));
		}

		$this->andWhere($andX);
	}


	/**
	 * @param IFederatedUser|null $initiator
	 */
	public function leftJoinCircle(?IFederatedUser $initiator = null): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();

		$alias = 'c';
		$this->generateCircleSelectAlias($alias, self::PREFIX_CIRCLE)
			 ->leftJoin(
				 $this->getDefaultSelectAlias(), CoreQueryBuilder::TABLE_CIRCLE, $alias,
				 $expr->eq($alias . '.unique_id', $this->getDefaultSelectAlias() . '.circle_id')
			 );

		$this->leftJoinOwner($alias);
		if (!is_null($initiator)) {
			$this->limitToInitiator($initiator, $alias, true);
		}
	}


	/**
	 * @param string $prefixBasedOn
	 */
	public function leftJoinBasedOnCircle(string $prefixBasedOn = self::PREFIX_BASED_ON): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		switch ($prefixBasedOn) {
			case self::PREFIX_OWNER_BASED_ON:
				$alias = 'obo';
				$memberAlias = 'o';
				break;

			case self::PREFIX_INITIATOR_BASED_ON:
				$alias = 'ibo';
				$memberAlias = 'init';
				break;

			default:
				$alias = 'mbo';
				$memberAlias = $this->getDefaultSelectAlias();
		}

		$expr = $this->expr();
		$this->generateCircleSelectAlias($alias, $prefixBasedOn)
			 ->leftJoin(
				 $memberAlias, CoreQueryBuilder::TABLE_CIRCLE, $alias,
				 $expr->eq($alias . '.unique_id', $memberAlias . '.single_id')
			 );
	}


	/**
	 * @param string $circleTableAlias
	 */
	public function leftJoinOwner(string $circleTableAlias = ''): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		if ($circleTableAlias === '') {
			$circleTableAlias = $this->getDefaultSelectAlias();
		}
		$expr = $this->expr();

		$alias = 'o';
		$this->generateMemberSelectAlias($alias, self::PREFIX_OWNER)
			 ->leftJoin(
				 $circleTableAlias, CoreQueryBuilder::TABLE_MEMBER, $alias,
				 $expr->andX(
					 $expr->eq($alias . '.circle_id', $circleTableAlias . '.unique_id'),
					 $expr->eq($alias . '.level', $this->createNamedParameter(Member::LEVEL_OWNER))
				 )
			 );

		$this->leftJoinBasedOnCircle(self::PREFIX_OWNER_BASED_ON);
	}


	/**
	 * Left join members to filter userId as initiator.
	 *
	 * @param IFederatedUser $initiator
	 * @param string $alias
	 * @param string $aliasCircle
	 */
	public function leftJoinInitiator(
		IFederatedUser $initiator,
		string $alias = 'init',
		string $aliasCircle = ''
	): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();
		$aliasCircle = ($aliasCircle === '') ? $this->getDefaultSelectAlias() : $aliasCircle;

		// Note: Some field about the initiator are overwritten by leftJoinMembership() to better fit the
		// real origin of the initiator
		$this->generateMemberSelectAlias(
			$alias, self::PREFIX_INITIATOR,
			[
				'user_id'   => $initiator->getUserId(),
				'user_type' => $initiator->getUserType(),
				'single_id' => $initiator->getSingleId(),
				'instance'  => $initiator->getInstance()
			]
		)
			 ->leftJoin(
				 $aliasCircle, CoreQueryBuilder::TABLE_MEMBER, $alias,
				 $expr->andX(
					 $expr->eq($alias . '.user_id', $this->createNamedParameter($initiator->getUserId())),
					 $expr->eq($alias . '.user_type', $this->createNamedParameter($initiator->getUserType())),
					 $expr->eq($alias . '.single_id', $this->createNamedParameter($initiator->getSingleId())),
					 $expr->eq(
						 $alias . '.instance', $this->createNamedParameter($this->getInstance($initiator))
					 )
				 )
			 );
	}


	private function leftJoinMembership(
		string $alias = 'mbs',
		string $aliasInit = 'init',
		string $aliasCircle = ''
	): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();
		$aliasCircle = ($aliasCircle === '') ? $this->getDefaultSelectAlias() : $aliasCircle;
		// Note: overwriting some data about initiator.
		$this->selectAlias($alias . '.member_id', self::PREFIX_INITIATOR . 'member_id');
		$this->selectAlias($alias . '.circle_id', self::PREFIX_INITIATOR . 'circle_id');
		$this->selectAlias($alias . '.level', self::PREFIX_INITIATOR . 'level');
		$this->leftJoin(
			$aliasCircle, CoreQueryBuilder::TABLE_MEMBERSHIP, $alias,
			$expr->andX(
				$expr->eq($alias . '.single_id', $aliasInit . '.single_id'),
				$expr->eq($alias . '.circle_id', $aliasCircle . '.unique_id')
			)
		);
	}


	/**
	 * left join members to check memberships of someone from instance
	 *
	 * @param string $instance
	 * @param string $alias
	 * @param string $aliasCircle
	 */
	private function leftJoinMemberFromInstance(
		string $instance, string $alias = 'mi', string $aliasCircle = 'c'
	) {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();

		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreQueryBuilder::TABLE_MEMBER, $alias,
			$expr->andX(
				$expr->eq($alias . '.circle_id', $aliasCircle . '.unique_id'),
				$expr->eq($alias . '.instance', $this->createNamedParameter($instance)),
				$expr->gte($alias . '.level', $this->createNamedParameter(Member::LEVEL_MEMBER))
			)
		);
	}


	/**
	 * left join circle is member of a circle from remote instance
	 *
	 * @param string $instance
	 * @param string $aliasRemoteOwner
	 * @param string $aliasCircle
	 */
	private function leftJoinMemberFromRemoteCircle(
		string $instance, string $aliasRemoteOwner = 'rco', string $aliasCircle = 'c'
	) {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$alias = 'rc';
		$expr = $this->expr();
		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreQueryBuilder::TABLE_MEMBER, $alias,
			$expr->andX(
				$expr->eq($alias . '.single_id', $aliasCircle . '.unique_id'),
				$expr->emptyString($alias . '.instance'),
				$expr->gte($alias . '.level', $this->createNamedParameter(Member::LEVEL_MEMBER))
			)
		);
		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreQueryBuilder::TABLE_MEMBER, $aliasRemoteOwner,
			$expr->andX(
				$expr->eq($alias . '.circle_id', $aliasRemoteOwner . '.circle_id'),
				$expr->eq($aliasRemoteOwner . '.instance', $this->createNamedParameter($instance)),
				$expr->eq($aliasRemoteOwner . '.level', $this->createNamedParameter(Member::LEVEL_OWNER))
			)
		);
	}


	/**
	 * Left join remotes to filter visibility based on RemoteInstance.
	 *
	 * @param string $instance
	 * @param string $alias
	 */
	public function leftJoinRemoteInstance(string $instance, string $alias = 'ri'): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();
		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreQueryBuilder::TABLE_REMOTE, $alias,
			$expr->eq($alias . '.instance', $this->createNamedParameter($instance))
		);
	}


	/**
	 * @param string $alias
	 * @param string $aliasCircle
	 * @param bool $mustBeMember
	 * @param bool $canBeVisitor
	 */
	protected function limitVisibility(
		string $alias = 'mbs',
		string $aliasCircle = '',
		bool $mustBeMember = false,
		bool $canBeVisitor = false
	) {
		$expr = $this->expr();
		$aliasCircle = ($aliasCircle === '') ? $this->getDefaultSelectAlias() : $aliasCircle;

		// Visibility to non-member is
		// - 0 (default), if initiator is member
		// - 2 (Personal), if initiator is owner)
		// - 4 (Visible to everyone)
		$orX = $expr->orX();
		$orX->add(
			$expr->andX($expr->gte($alias . '.level', $this->createNamedParameter(Member::LEVEL_MEMBER)))
		);
		$orX->add(
			$expr->andX(
				$expr->bitwiseAnd($aliasCircle . '.config', Circle::CFG_PERSONAL),
				$expr->eq($alias . '.level', $this->createNamedParameter(Member::LEVEL_OWNER))
			)
		);
		if (!$mustBeMember) {
			$orX->add($expr->bitwiseAnd($aliasCircle . '.config', Circle::CFG_VISIBLE));
		}
		if ($canBeVisitor) {
			// TODO: should find a better way, also filter on remote initiator on non-federated ?
			$orX->add($expr->gte($aliasCircle . '.config', $this->createNamedParameter(0)));
		}
		$this->andWhere($orX);


//		$orTypes = $this->generateLimit($qb, $circleUniqueId, $userId, $type, $name, $forceAll);
//		if (sizeof($orTypes) === 0) {
//			throw new ConfigNoCircleAvailableException(
//				$this->l10n->t(
//					'You cannot use the Circles Application until your administrator has allowed at least one type of circles'
//				)
//			);
//		}

//		$orXTypes = $this->expr()
//						 ->orX();
//		foreach ($orTypes as $orType) {
//			$orXTypes->add($orType);
//		}
//
//		$qb->andWhere($orXTypes);
	}


	/**
	 * CFG_SINGLE, CFG_HIDDEN and CFG_BACKEND means hidden from listing.
	 *
	 * @param string $alias
	 * @param int $flag
	 */
	public function filterCircles(
		int $flag = Circle::CFG_SINGLE | Circle::CFG_HIDDEN | Circle::CFG_BACKEND,
		string $alias = ''
	): void {
		if ($flag === 0) {
			return;
		}

		$expr = $this->expr();
		$hide = $expr->andX();
		$alias = ($alias === '') ? $this->getDefaultSelectAlias() : $alias;
		foreach (Circle::$DEF_CFG as $cfg => $v) {
			if ($flag & $cfg) {
				$hide->add($this->createFunction('NOT') . $expr->bitwiseAnd($alias . '.config', $cfg));
			}
		}

		$this->andWhere($hide);
	}


	/**
	 * - global_scale: visibility on all Circles
	 * - trusted: visibility on all FEDERATED Circle if owner is local
	 * - external: visibility on all FEDERATED Circle if owner is local and:
	 *    - with if Circle contains at least one member from the remote instance
	 *    - one circle from the remote instance contains the local circle as member, and confirmed (using
	 *      sync locally)
	 * - passive: like external, but the members list will only contains member from the local instance and
	 * from the remote instance.
	 *
	 * @param bool $sensitive
	 * @param string $alias
	 * @param string $aliasRemoteOwner
	 * @param string $aliasCircle
	 * @param string $aliasOwner
	 * @param string $aliasMembers
	 */
	protected function limitRemoteVisibility(
		bool $sensitive = true,
		string $alias = 'ri',
		string $aliasRemoteOwner = 'rco',
		string $aliasCircle = 'c',
		string $aliasOwner = 'o',
		string $aliasMembers = 'mi'
	) {
		$expr = $this->expr();

		$orX = $expr->orX();
		$orX->add(
			$expr->eq($alias . '.type', $this->createNamedParameter(RemoteInstance::TYPE_GLOBAL_SCALE))
		);

		$orExtOrPassive = $expr->orX();
		$orExtOrPassive->add(
			$expr->eq($alias . '.type', $this->createNamedParameter(RemoteInstance::TYPE_EXTERNAL))
		);
		if (!$sensitive) {
			$orExtOrPassive->add(
				$expr->eq($alias . '.type', $this->createNamedParameter(RemoteInstance::TYPE_PASSIVE))
			);
		} else {
			if ($this->getDefaultSelectAlias() === 'm') {
				$orExtOrPassive->add($this->limitRemoteVisibility_Sensitive_Members($alias));
			}
		}


		$orInstance = $expr->orX();
		$orInstance->add($expr->isNotNull($aliasMembers . '.instance'));
		$orInstance->add($expr->isNotNull($aliasRemoteOwner . '.instance'));

		$andExternal = $expr->andX();
		$andExternal->add($orExtOrPassive);
		$andExternal->add($orInstance);

		$orExtOrTrusted = $expr->orX();
		$orExtOrTrusted->add($andExternal);
		$orExtOrTrusted->add(
			$expr->eq($alias . '.type', $this->createNamedParameter(RemoteInstance::TYPE_TRUSTED))
		);

		$andTrusted = $expr->andX();
		$andTrusted->add($orExtOrTrusted);
		$andTrusted->add($expr->bitwiseAnd($aliasCircle . '.config', Circle::CFG_FEDERATED));
		$andTrusted->add($expr->emptyString($aliasOwner . '.instance'));
		$orX->add($andTrusted);

		$this->andWhere($orX);
	}


	/**
	 * Limit visibility on Sensitive information when search for members.
	 *
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	private function limitRemoteVisibility_Sensitive_Members(string $alias = 'ri'): ICompositeExpression {
		$expr = $this->expr();
		$andPassive = $expr->andX();
		$andPassive->add(
			$expr->eq($alias . '.type', $this->createNamedParameter(RemoteInstance::TYPE_PASSIVE))
		);

		$orMemberOrLevel = $expr->orX();
		$orMemberOrLevel->add(
			$expr->eq($this->getDefaultSelectAlias() . '.instance', $alias . '.instance')
		);
		// TODO: do we need this ? (display members from the local instance)
		$orMemberOrLevel->add(
			$expr->emptyString($this->getDefaultSelectAlias() . '.instance')
		);

		$orMemberOrLevel->add(
			$expr->eq(
				$this->getDefaultSelectAlias() . '.level',
				$this->createNamedParameter(Member::LEVEL_OWNER)
			)
		);
		$andPassive->add($orMemberOrLevel);

		return $andPassive;
	}


	/**ha
	 *
	 * @param int $flag
	 */
	public function filterConfig(int $flag): void {
		$this->andWhere($this->expr()->bitwiseAnd($this->getDefaultSelectAlias() . '.config', $flag));
	}


	/**
	 * @param string $alias
	 * @param string $prefix
	 * @param array $default
	 *
	 * @return CoreRequestBuilder
	 */
	private function generateCircleSelectAlias(string $alias, string $prefix, array $default = []): self {
		$fields = [
			'unique_id', 'name', 'display_name', 'source', 'description', 'settings', 'config',
			'contact_addressbook', 'contact_groupname', 'creation'
		];

		$this->generateSelectAlias($fields, $alias, $prefix, $default);

		return $this;
	}

	/**
	 * @param string $alias
	 * @param string $prefix
	 * @param array $default
	 *
	 * @return $this
	 */
	private function generateMemberSelectAlias(string $alias, string $prefix, array $default = []): self {
		$fields = [
			'circle_id', 'single_id', 'user_id', 'user_type', 'member_id', 'instance', 'cached_name',
			'cached_update', 'status', 'level', 'note', 'contact_id', 'contact_meta', 'joined'
		];

		$this->generateSelectAlias($fields, $alias, $prefix, $default);

		return $this;
	}

}

