<?php

/**
 * WeEngine Document System
 *
 * (c) We7Team 2019 <https://www.w7.cc>
 *
 * This is not a free software
 * Using it under the license terms
 * visited https://www.w7.cc for more details
 */

namespace W7\App\Command\Install;

use W7\App\Model\Logic\UserLogic;
use W7\Console\Command\CommandAbstract;
use W7\Core\Exception\CommandException;

class InitCommand extends CommandAbstract
{
	protected function handle($options)
	{
		try {
			// 是否已安装
			$lockFile = RUNTIME_PATH . '/install.lock';
			if (file_exists($lockFile)) {
				throw new CommandException('文档系统已经安装，如果需要重新安装请手动删除 runtime/install.lock 文件');
			}

			// 版本检查
			$this->checkExtension();

			// 生成配置文件
			$config = $this->installConfig();
			$this->generateConfig($config);

			// 初始化数据库
			$this->initDatabase($config);

			// 生成lock文件
			file_put_contents($lockFile, 'success');

			$this->output->success('安装已完成！提示：请按照文档配置，启动相关服务');
		} catch (\Exception $e) {
			$this->dropDatabase($config);
			$this->output->error($e->getMessage());
		}
	}

	private function generateConfig($config)
	{
		$env = file_get_contents(BASE_PATH . '/install/.env.template');
		// server
		$env = str_replace('{{SERVER_HTTP_PORT}}', $config['server_port'], $env);
		// db
		$env = str_replace('{{DATABASE_DEFAULT_DATABASE}}', $config['db_database'], $env);
		$env = str_replace('{{DATABASE_DEFAULT_HOST}}', $config['db_host'], $env);
		$env = str_replace('{{DATABASE_DEFAULT_PORT}}', $config['db_port'], $env);
		$env = str_replace('{{DATABASE_DEFAULT_USERNAME}}', $config['db_username'], $env);
		$env = str_replace('{{DATABASE_DEFAULT_PASSWORD}}', $config['db_password'], $env);
		$env = str_replace('{{DATABASE_DEFAULT_PREFIX}}', $config['db_prefix'], $env);
		// cache
		if ($config['cache_driver'] == 'redis') {
			$env = str_replace('{{CACHE_DRIVER}}', $config['cache_driver'], $env);
			$env = str_replace('{{CACHE_DEFAULT_HOST}}', $config['cache_host'], $env);
			$env = str_replace('{{CACHE_DEFAULT_PORT}}', $config['cache_port'], $env);
		} else {
			$env = str_replace('{{CACHE_DRIVER}}', $config['cache_driver'], $env);
			$env = preg_replace('/CACHE_DEFAULT_HOST[\s\S]+?}}/', '', $env);
			$env = preg_replace('/CACHE_DEFAULT_PORT[\s\S]+?}}/', '', $env);
		}

		if (file_put_contents(BASE_PATH . '/.env', $env) === false) {
			throw new CommandException('配置文件写入失败！');
		}
		$this->output->success('配置文件已生成！');
		$this->segmentation();
	}

	/**
	 * @param $config
	 * @throws CommandException
	 */
	private function initDatabase($config)
	{
		// 创建数据库
		try {
			$connect = new \PDO("mysql:host={$config['db_host']};port={$config['db_port']}", $config['db_username'], $config['db_password']);
			$connect->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			$sql = "CREATE DATABASE IF NOT EXISTS {$config['db_database']} DEFAULT CHARSET utf8 COLLATE utf8_unicode_ci;";
			$connect->exec($sql);
			$statement = $connect->query("SHOW DATABASES LIKE '{$config['db_database']}';");
			if (empty($statement->fetch())) {
				throw new CommandException('创建数据库失败！');
			}

			$connect->exec("USE {$config['db_database']};");
			$statement = $connect->query("SHOW TABLES LIKE '{$config['db_prefix']}%';");
			if (!empty($statement->fetch())) {
				throw new CommandException('您的数据库不为空，请重新建立数据库或清空该数据库或更改表前缀！');
			}

			// 导入数据
			$importSql = file_get_contents(BASE_PATH . '/install/document.sql');
			$importSql = str_replace('ims_', $config['db_prefix'], $importSql);
			$connect->exec($importSql);
			$connect = null;

			// 创建系统管理员账号
			$this->createAdmin($config);

			$this->output->success('数据库初始化成功！');
			$this->segmentation();
		} catch (\PDOException $e) {
			throw new CommandException($e->getMessage());
		}
	}

	private function dropDatabase($config)
	{
		$connect = new \PDO("mysql:host={$config['db_host']};port={$config['db_port']}", $config['db_username'], $config['db_password']);
		$sql = "DROP DATABASE IF EXISTS {$config['db_database']};";
		$connect->exec($sql);
		$connect = null;
	}

	/**
	 * @param $config
	 * @throws CommandException
	 */
	private function createAdmin($config)
	{
		try {
			$connect = new \PDO("mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_database']}", $config['db_username'], $config['db_password']);
			$userLogic = new UserLogic();
			$username = $config['admin_username'];
			$password = $userLogic->userpassEncryption($username, $config['admin_password']);
			$userTable = $config['db_prefix'] . 'user';
			$sql = "INSERT INTO `{$userTable}` (`username`, `is_ban`, `userpass`, `remark`, `has_privilege`, `created_at`, `updated_at`) VALUE ('{$username}', 0, '{$password}', '超管', 1, 0, 0)";
			$connect->exec($sql);
			$statement = $connect->query("SELECT * FROM {$userTable} WHERE username = '{$username}'");
			if (empty($statement->fetch())) {
				throw new CommandException('创建系统管理员失败！');
			}
			$connect = null;
		} catch (\PDOException $e) {
			throw new CommandException($e->getMessage());
		}
	}

	private function checkExtension()
	{
		$this->output->info('检查PHP扩展: ');
		$this->output->writeln('');

		if (version_compare(PHP_VERSION, '7.0.0', '<')) {
			throw new CommandException('PHP 版本必须>= 7.0.0');
		}

		$extension = ['pdo_mysql', 'mbstring', 'swoole'];
		foreach ($extension as $ext) {
			if (!extension_loaded($ext)) {
				throw new CommandException($ext . ' 扩展未安装');
			}
		}

		if (version_compare(swoole_version(), '4.2.3', '<')) {
			throw new CommandException('swoole 版本必须>= 4.2.3');
		}

		$file = BASE_PATH . '/install.txt';
		if (file_put_contents($file, '') === false) {
			throw new CommandException('请检查' . BASE_PATH . '目录权限！');
		}
		unlink($file);

		$this->output->success('PHP扩展已检查完毕！');
		$this->segmentation();
	}

	private function installConfig()
	{
		// 验证规则
		$validate = [
			'host' => '/[\w-\.]{5,64}/',
			'port' => '/[1-9]\d{0,4}/',
			'password' => '/\w{6,32}/'
		];
		$install = [
			'server' => [
				'option' => '服务',
				'value' => [
					'port' => [
						'name' => '端口',
						'default' => 80,
						'validate' => $validate['port']
					]
				]
			],
			'db' => [
				'option' => '数据库',
				'value' => [
					'host' => [
						'name' => '地址',
						'default' => '127.0.0.1',
						'validate' => $validate['host']
					],
					'port' => [
						'name' => '端口',
						'default' => '3306',
						'validate' => $validate['port']
					],
					'database' => [
						'name' => '名称',
						'default' => 'we7_document',
						'validate' => '/[a-z_]{5,24}/'
					],
					'prefix' => [
						'name' => '表前缀',
						'default' => 'imi_',
						'validate' => '/[a-z][a-z_]{1,20}/'
					],
					'username' => [
						'name' => '用户名',
						'default' => 'root',
						'validate' => '/\w{4,24}/'
					],
					'password' => [
						'type' => 'hidden',
						'name' => '密码',
						'default' => '',
						'validate' => $validate['password']
					]
				]
			],
			'cache' => [
				'option' => '缓存',
				'value' => [
					'driver' => [
						'name' => '驱动, 只支持[database,redis]',
						'default' => 'database', // database, redis
						'validate' => '/(database|redis)/'
					],
					'host' => [
						'name' => '地址',
						'default' => '127.0.0.1',
						'validate' => $validate['host']
					],
					'port' => [
						'name' => '端口',
						'default' => 6379,
						'validate' => $validate['port']
					],
				]
			],
			'admin' => [
				'option' => '管理员',
				'value' => [
					'username' => [
						'name' => '用户名',
						'default' => 'admin',
						'validate' => '/\w{4,24}/'
					],
					'password' => [
						'type' => 'hidden',
						'name' => '密码',
						'default' => '',
						'validate' => $validate['password']
					],
					'passwordConfirm' => [
						'type' => 'hidden',
						'name' => '确认密码',
						'default' => '',
						'validate' => 'reconfirm',
						'confirm' => 'password'
					],
				]
			]
		];

		$config = [];
		foreach ($install as $option => $value) {
			$this->output->info("请设置{$value['option']}相关信息: ");
			foreach ($value['value'] as $key => $item) {
				if (empty($item['name'])) {
					throw new CommandException("{$value['option']}{$item['name']}不能为空");
				}

				$configKey = $option . '_' . $key;
				if (isset($item['type']) && $item['type'] == 'hidden') {
					$config[$configKey] = $this->output->askHidden("请输入{$value['option']}{$item['name']}");
				} else {
					$config[$configKey] = $this->output->ask("请输入{$value['option']}{$item['name']}", $item['default']);
				}

				// 如果缓存不使用redis,直接跳过
				if ($option == 'cache' && $config[$configKey] == 'database') {
					break;
				}

				// 数据验证
				$reg = '/\w+/';
				if (isset($item['validate']) && $item['validate']) {
					$reg = $item['validate'];
				}
				if ($reg == 'reconfirm') {
					if ($config[$configKey] != $config[$option . '_' . $item['confirm']]) {
						throw new CommandException('两次输入的密码不一样！');
					}
				} else {
					if (!preg_match($reg, $config[$configKey])) {
						throw new CommandException("{$value['option']}{$item['name']}格式不正确！");
					}
				}
			}

			$this->segmentation();
		}

		return $config;
	}

	private function segmentation()
	{
		$this->output->writeln('');
	}
}
