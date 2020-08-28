<?php
namespace DB\DB;

require_once __DIR__ . '/../../vendor/redbean/rb-sqlite.php';


class AppAgnosticDB implements DBInterface
{
    private $engine;

    public function __construct(
        string $engine,
        string $sqlite_path = null,
        string $db_host = null,
        string $db_port = null,
        string $db_name = null,
        string $db_username = null,
        string $db_password = null
    )
    {
        $this->engine = $engine;
        switch  ($this->engine) {
            case 'sqlite':
                \R::setup( "sqlite:$sqlite_path" );
            break;
            case 'mysql':
                \R::setup( "mysql:host=$db_host;dbname=$db_name;port=$db_port", $db_username, $db_password );
            break;
            case 'pgsql':
                \R::setup( "pgsql:host=$db_host;dbname=$db_name;port=$db_port", $db_username, $db_password );
            break;
            default:
                throw new \Exception("Engine $this->engine not implemented");
        }
        \R::freeze( TRUE );
    }
    public function getApp(): string
    {
        return '';
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function execInTransaction(string $sql): bool
    {
        return false;
    }
    
    public function exec(string $sql): bool
    {
        return \R::exec($sql) !== false;
    }
    
    public function backupBeforeEdit(string $table, int $id, string $query, array $values = [])
    {

    }

    public function query(string $query, array $values = null, string $type = null, bool $fetch_style = false )
    {
        try {
            $pdo = \R::getDatabaseAdapter()->getDatabase()->getPDO();

            $query = trim($query);

			$sql = $pdo->prepare($query);

			if ( !$values ) $values = [];

			$flag = $sql->execute($values);


			if (is_int($type)) {
				return $sql->fetchColumn($type);
			}

			switch ($type) {
				case 'boolean':
					return $flag;
					break;

				case 'read':
				case false:
				default:
					$fetch_style  = $fetch_style ? \PDO::FETCH_NUM : \PDO::FETCH_ASSOC;
					return $sql->fetchAll($fetch_style);
					break;

				case 'id':
					return $pdo->lastInsertId();
					break;

				case 'affected':
					return $sql->rowCount();
					break;
			}
		} catch (\Throwable $e) {
            throw new \Exception($e);
            
		}
    }

    public function beginTransaction()
    {
        \R::begin();
    }

    public function commit()
    {
        \R::commit();
    }

    function rollBack()
    {
        \R::rollback();
    }
}