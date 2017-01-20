<?php
/**
 * 角色奖励类
 */
class RoleReward{
	private $roletype = array();//对应fundhistorytype 的id
	private $rolename = array();//对应fundhistorytype	的name

	public function __construct() {
		$this->roletype[1]=209;//209 :fundhistroy 表typeid
		$this->roletype[2]=214;
		$this->roletype[3]=208;
		$this->roletype[4]=215;
		$this->roletype[5]=213;
		$this->roletype[6]=216;
		$this->roletype[7]=217;
		$this->roletype[8]=218;
		$this->roletype[9]=219;
		$this->rolename[1]='普通用户推荐奖励';//fundhistroy 表备注
		$this->rolename[2]='vip用户推荐奖励';
		$this->rolename[3]='商户推荐奖励';
		$this->rolename[4]='vip小S推荐奖励';
		$this->rolename[5]='合伙人推荐奖励';
		$this->rolename[6]='vip合伙人推荐奖励';
		$this->rolename[7]='钻石小S推荐奖励';
		$this->rolename[8]='大S推荐奖励';
		$this->rolename[9]='普通小S推荐奖励';
	}
	/**
	 * 收益奖励发放新方法 
	 */
	public function IncomeRewardNew($RepayDate = ''){
		//默认执行当天
    	if(empty($RepayDate)){
    		$RepayDate = date('Y-m-d');
    	}
    	$excutemodel=new Model();
    	//判断是否重发
    	$sql_prevent="select * from prevent where status=1 and Substring(should_repaytime, 1, 10) ='{$RepayDate}'";
    	$result= $excutemodel->query($sql_prevent);
    	if(!empty($result)){
    		return buildReturnData('', '今天已经发放奖励 sql：'.$excutemodel->getLastSql(), 0);
    	}

    	//创建放重发纪律默认失败状态
    	$arr['should_repaytime']=now();
    	$arr['status']=0;
    	$arr['created_at']=now();
    	$model=M('prevent');
		$prevent_id = $model->add($arr);
	    if(empty($prevent_id)){
	    	return buildReturnData('', '创建防重发prevent表失败'.$model->getLastSql(), 0);
	    }
    	//算钱开始最基础的数据 一级
		
		$rateSql = "CASE WHEN r.roletype in (3,4,7,8) 
	THEN CASE 
				WHEN m.`tzamount`< 10000 THEN u.interest*3/100
				WHEN m.`tzamount`< 50000 THEN u.interest*5/100					
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  < 10000 THEN u.interest*3/100
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  between 10000 and 49999.99 THEN u.interest*5/100
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  between 50000 and 99999.99 THEN u.interest*6/100 
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  between 100000 and 149999.99 THEN u.interest*7/100 
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  between 150000 and 199999.99 THEN u.interest*8/100 
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  between 200000 and 299999.99 THEN u.interest*9/100 
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  between 300000 and 399999.99 THEN u.interest*10/100 
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  between 400000 and 499999.99 THEN u.interest*11/100 
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  between 500000 and 649999.99 THEN u.interest*12/100 
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  between 650000 and 799999.99 THEN u.interest*13/100 
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  between 800000 and 999999.99 THEN u.interest*14/100 
				WHEN m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount`  >=1000000 THEN (u.interest)*15/100 
				END
	ELSE u.interest*r.yieldrate/100 END";

		//用于profile,memberinfo,fundhistory等,汇总
		$usergatheringwhereSmallFrom = "from (
		SELECT uu.`tuid`, SUM(uu.`interest`) as interest,uu.troleid from `usergathering` as uu where uu.record_type=1  and substring(uu.repaytime,1,10) = '{$RepayDate}' and uu.`tuid` >0 GROUP BY  uu.`tuid` 
		) as u
		INNER JOIN `memberinfo` as m on u.tuid = m.`uid`
		INNER JOIN `role`  as r on u.`troleid`  = r.id and r.`isdel`  = 0 ";
		
		//用于hisotry记录,不汇总
		$usergatheringwhereSmallFrom_for_history = "from (
		SELECT uu.`tuid`,uu.uid, uu.`interest` as interest,uu.troleid  from `usergathering` as uu where uu.record_type=1  and substring(uu.repaytime,1,10) = '{$RepayDate}' and uu.`tuid` >0
		) as u
		INNER JOIN `memberinfo` as m on u.tuid = m.`uid`
		INNER JOIN `role`  as r on u.`troleid`  = r.id and r.`isdel`  = 0 ";
		
		
		$usergatheringwhere = "SELECT 1 as type,u.*,m.`tzamount` ,m.`xxhqtzamount` ,m.`xxdttzamount` ,m.`xxlhtzamount` ,r.id,r.`roletype`,m.`tzamount`+m.`xxhqtzamount` +m.`xxdttzamount` +m.`xxlhtzamount` as total,
				{$rateSql} as money,now()
		{$usergatheringwhereSmallFrom} ";
		

    	//发奖励profile
    	$sql = "update profile p inner join ($usergatheringwhere) c on p.uid = c.tuid set p.usableamount = p.usableamount+c.money,p.totalamount = p.totalamount+c.money";
    	$ret = $excutemodel->execute($sql);
    	if($ret===false){
    		return buildReturnData('', '一级批量加钱profile失败'.$excutemodel->getLastSql(), 0);
    	}
    	//写memberinfo
    	$sql2 = "update memberinfo m inner join ($usergatheringwhere) c on m.uid = c.tuid set m.rcamount = m.rcamount+c.money";
    	$ret = $excutemodel->execute($sql2);
    	if($ret===false){
    		return buildReturnData('', '一级批量更新memberinfo表失败'.$excutemodel->getLastSql(), 0);
    	}
    	//写发奖记录awardrecords

$awardrecordssql="select u.tuid,u.uid,r.id,1 as type,interest as ysamount,{$rateSql} as money,now() 
{$usergatheringwhereSmallFrom_for_history}";
    	$sql3="insert into awardrecords (`uid`,`btuid`,`roleid`,`type`,`ysamount`,`pfamount`,`created_at`) {$awardrecordssql}";
    	$ret = $excutemodel->execute($sql3);
	    if($ret===false){
	    	return buildReturnData('', '一级批量插入awardrecords表失败'.$excutemodel->getLastSql(), 0);
	    }

    	//写交易记录fundhistory

		$fundsql="select u.tuid,CASE r.roletype 
			when 1 then  220 
			when 2 then  214 
			when 3 then  219 
			when 4 then  215 
			when 7 then  217 
			when 8 then  218 
			end as typeid ,
			{$rateSql} as money ,
pr.totalamount,pr.tyamount,pr.yxamount,pr.frostamount,pr.usableamount,m.hqtzamount,m.dttzamount,m.lhtzamount,
'awardrecords' as tname,
CASE r.roletype 
when 1 then  '普通用户推荐奖励' 
when 2 then  'vip用户推荐奖励' 
when 3 then  '普通小S推荐奖励' 
when 4 then  'vip小S推荐奖励'  
when 7 then  '钻石小S推荐奖励' 
when 8 then  '大S推荐奖励'
end as remark,now() 
{$usergatheringwhereSmallFrom} INNER JOIN profile as pr on u.tuid = pr.uid";
			
			
	    $sql4 = "insert into fundhistory(`uid`,`typeid`,`income`,`totalamount`,`tyamount`,`yxamount`,`frostamount`,`usableamount`,`hqtzamount`,`dttzamount`,`lhtzamount`,`tname`,`remark`,`created_at`) {$fundsql}";
	    $ret = $excutemodel->execute($sql4);
	    if($ret===false){
	    	return buildReturnData('', '一级批量插入fundhistory表失败'.$excutemodel->getLastSql(), 0);
	    }
	    
	    
    	// 二级奖励派发------------------------------------------------------------------------
    	//原始赛选条件,用于profile,memberinfo,historyd等汇总计算
	    $sql_2_from = "from (
    SELECT aa.uid,aa.ysamount as ysamount,aa.pfamount as pfamount from `awardrecords` as aa where substr(aa.created_at,1,10) = '{$RepayDate}' and aa.`type` = 1
) as aaa
INNER JOIN `partner`  as pt on aaa.uid = pt.`uid` 
INNER JOIN `memberinfo` as m on m.`uid`  = pt.`puid`
INNER JOIN `role` as r on m.`roleid`  = r.`id`
INNER JOIN `profile` as p on m.`uid`  = p.`uid`
where m.tzamount>=100000 and pt.`puid` >0 and pt.`status`  = 1 and pt.`isdel` = 0 and r.roletype = 8
GROUP BY pt.`puid`
";
	    //原始赛选条件,不用group by,用于记录
	    $sql_2_from_for_history = "from (
    SELECT aa.uid,aa.ysamount as ysamount,aa.pfamount as pfamount from `awardrecords` as aa where substr(aa.created_at,1,10) = '{$RepayDate}' and aa.`type` = 1
) as aaa
INNER JOIN `partner`  as pt on aaa.uid = pt.`uid` 
INNER JOIN `memberinfo` as m on m.`uid`  = pt.`puid`
INNER JOIN `role` as r on m.`roleid`  = r.`id`
where m.tzamount>=100000 and pt.`puid` >0 and pt.`status`  = 1 and pt.`isdel` = 0 and r.roletype = 8";
	    
	   //用于profile和memberinfo相关字段变更的sql
	   $sql_2 = "SELECT pt.puid as tuid,sum(aaa.ysamount) as ysamount,sum(aaa.ysamount)*r.`yieldrate`*0.01 as money  {$sql_2_from}";

		//发奖励profile
		$sql5 = "update profile p inner join ($sql_2) c on p.uid = c.tuid set p.usableamount = p.usableamount+c.money,p.totalamount = p.totalamount+c.money";
    	$ret = $excutemodel->execute($sql5);
    	if($ret===false){
    		return buildReturnData('', '二级批量加钱profile失败'.$excutemodel->getLastSql(), 0);
    	}
		//写memberinfo
		$sql6 = "update memberinfo m inner join ($sql_2) c on m.uid = c.tuid set m.rcamount = m.rcamount+c.money";
    	$ret = $excutemodel->execute($sql6);
    	if($ret===false){
    		return buildReturnData('', '二级批量更新memberinfo表失败'.$excutemodel->getLastSql(), 0);
    	}
		

		//写发奖记录awardrecords--------------------------
		//赛选sql
		$awardrecordssql_2 = "SELECT pt.puid as tuid,aaa.uid as btuid,m.`roleid` as id,2 as type,aaa.ysamount as ysamount,aaa.ysamount*r.`yieldrate`*0.01 as money,now() {$sql_2_from_for_history} ";
		 //插入	
		$sql7="insert into awardrecords (`uid`,`btuid`,`roleid`,`type`,`ysamount`,`pfamount`,`created_at`) {$awardrecordssql_2}";
    	$ret = $excutemodel->execute($sql7);
	    if($ret===false){
	    	return buildReturnData('', '二级批量插入awardrecords表失败'.$excutemodel->getLastSql(), 0);
	    }
	    //写发奖记录awardrecords--------------------------
	    
		//写账单------------------------------------------
		//赛选sql
	    $fundsql_2 = "select pt.puid as tuid,218,sum(aaa.ysamount)*r.`yieldrate`*0.01 as money,totalamount,tyamount,yxamount,frostamount,usableamount,hqtzamount,dttzamount,lhtzamount,'awardrecords','大S推荐奖励',now()
	    	{$sql_2_from}";
	    //插入	
		$sql8 = "insert into fundhistory(`uid`,`typeid`,`income`,`totalamount`,`tyamount`,`yxamount`,`frostamount`,`usableamount`,`hqtzamount`,`dttzamount`,`lhtzamount`,`tname`,`remark`,`created_at`) {$fundsql_2}";
	    $ret = $excutemodel->execute($sql8);
	    if($ret===false){
	    	return buildReturnData('', '二级批量插入fundhistory表失败'.$excutemodel->getLastSql(), 0);
	    }
	    //写账单------------------------------------------
	    
    	//修改防重发
    	$sql9="update prevent set status=1 where id={$prevent_id}";
		$ret = $model->execute($sql9);
	    if(empty($ret)){
	    	return buildReturnData('', '修改防重发prevent表失败'.$model->getLastSql(), 0);
	    }
	    return buildReturnData('', date('Y-m-d').'角色奖励发放完成', 1);
	}
	

	
	


	/**
	 * 收益奖励发放  ----- 用新的方法-----旧的无效了
	 * @param [type] $uid  		用户uid
	 * @param [type] $amount  	为计算比率的奖励
	 */
	public function IncomeReward($uid,$amount){
		if(empty($uid)){
			return  buildReturnData('', 'uid为空', 0);
		}
		//判断角色
		$role=$this->Judgingrole($uid);
		if($role['status']==0){
			return  buildReturnData('', 'uid为:'.$uid.'未查询到相关角色信息 sql:'.$role['info'], 0);
		}

		$role=$role['data'];
		//判断是否重发
		$ret=$this->JudgingRepeatReward($uid,$this->roletype[$role['roletype']]);
		if($ret['status']==0){
			return  buildReturnData('', 'uid为:'.$uid.'已经发放过奖励 sql:'.$ret['info'], 0);
		}
		//开始计算奖励
		

		if(empty($role['yieldrate'])){
			return  buildReturnData('', 'uid为:'.$uid.'角色奖励百分比为0或者空！角色id：'.$role['id'], 0);
		}
		
		//还有钱
		//算下级的下级
		$money = 0;
		if(in_array($role['roletype'],array(5,6))){
			$money=$this->getjuniormembernew($uid);			
		}

		$amount=$money+$amount;//还未计算比率的收益

		$amout=round($amount*$role['yieldrate']/100,2);

		//写profile表
		$profileModel=M('profile');
		$profiledata['totalamount']=array('exp','totalamount+'.$amout);
		$profiledata['usableamount'] = array('exp', 'usableamount+'.$amout);
		$ret=$profileModel->where(array('uid'=>$uid))->save($profiledata);
		if(empty($ret)){
			return  buildReturnData('', 'uid为:'.$uid.'更新profile表失败 sql:'.$profileModel->getLastsql(), 0);
		}

		//写memberinfo表
		$memberinfoModel=M('memberinfo');
		$memberinfodata['rcamount']=array('exp','rcamount+'.$amout);
		$res=$memberinfoModel->where(array('uid'=>$uid))->save($memberinfodata);
		if(empty($res)){
			return  buildReturnData('', 'uid为:'.$uid.'更新memberinfo表失败 sql:'.$memberinfoModel->getLastsql(), 0);
		}

		//写防止重发表
		$preventModel=M('prevent');
		$preventdata['uid']=$uid;
		$preventdata['roleid']=$role['id'];
		$preventdata['typeid']=$this->roletype[$role['roletype']];
		$preventdata['pfamount']=$amout;
		$preventdata['created_at']=array('exp','now()');
		$preventid=$preventModel->add($preventdata);
		if(empty($preventid)){
			return  buildReturnData('', 'uid为:'.$uid.'插入prevent表失败 sql:'.$preventModel->getLastsql(), 0);
		}

		//写fundhistory表
		$fundHistoryModel =  new FundhistoryModel();
		$Param = new FundhistoryParamAdd();
		$Param->uid = $uid;
		$Param->typeid = $this->roletype[$role['roletype']];//类型
		$Param->tname = 'prevent';
		$Param->tname_fk = $preventid;
		$Param->income = $amout;
		$Param->remark = $this->rolename[$role['roletype']];
		$Param->productid = 0;
		$retid = $fundHistoryModel->AddItem($Param);		

		if(empty($retid)){
			return  buildReturnData('', 'uid为:'.$uid.'插入fundhistory表失败 sql:'.$fundHistoryModel->getLastsql(), 0);
		} 

		return  buildReturnData('', 'uid为:'.$uid.'vip奖励发放完成', 1);
	}

	/**
	 * 注册推荐奖励
	 * @param [type] $uid 推荐人uid
	 * * @param [type] $btuid 被推荐人uid
	 */
	public function RecommendedReward($uid,$btuid){
		if(empty($uid)){
			return buildReturnData('', 'uid为空', 0);
		}
		//判断角色
		$role=$this->Judgingrole($uid);
		if($role['status']==0){
			return  buildReturnData('', 'uid为:'.$uid.'未查询到相关角色信息 sql:'.$role['info'], 0);
		}

		$role=$role['data'];
		//判断是否重发
		$ret=$this->JudgingRepeatReg($uid,$btuid);
		if($ret['status']==0){
			return  buildReturnData('', 'uid为:'.$uid.'已经发放过奖励 sql:'.$ret['info'], 0);
		}
		//开始计算奖励
		$amout=$role['reg_award']?$role['reg_award']+0:0;
		if(empty($amout)){
			return  buildReturnData('', 'uid为:'.$uid.'的角色推荐奖励为空的或为0', 0);
		}
		//写profile表
		$profileModel=M('profile');
		$profiledata['totalamount']=array('exp','totalamount+'.$amout);
		$profiledata['usableamount'] = array('exp', 'usableamount+'.$amout);
		$ret=$profileModel->where(array('uid'=>$uid))->save($profiledata);
		if(empty($ret)){
			return  buildReturnData('', 'uid为:'.$uid.'更新profile表失败 sql:'.$profileModel->getLastsql(), 0);
		}

		//写memberinfo表
		$memberinfoModel=M('memberinfo');
		$memberinfodata['rcamount']=array('exp','rcamount+'.$amout);
		$res=$memberinfoModel->where(array('uid'=>$uid))->save($memberinfodata);
		if(empty($res)){
			return  buildReturnData('', 'uid为:'.$uid.'更新memberinfo表失败 sql:'.$memberinfoModel->getLastsql(), 0);
		}
		//写防止重发表
		
		$preventModel=M('prevent');
		$preventdata['uid']=$uid;
		$preventdata['roleid']=$role['id'];
		$preventdata['btuid']=$btuid;
		$preventdata['typeid']=$this->roletype[$role['roletype']];
		$preventdata['pfamount']=$amout;
		$preventdata['created_at']=array('exp','now()');
		$preventid=$preventModel->add($preventdata);
		if(empty($preventid)){
			return  buildReturnData('', 'uid为:'.$uid.'插入prevent表失败 sql:'.$preventModel->getLastsql(), 0);
		}

		//写fundhistory表
		$fundHistoryModel =  new FundhistoryModel();
		$Param = new FundhistoryParamAdd();
		$Param->uid = $uid;
		$Param->typeid = $this->roletype[$role['roletype']];//类型
		$Param->tname = 'prevent';
		$Param->tname_fk = $preventid;
		$Param->income = $amout;
		$Param->remark = $this->rolename[$role['roletype']];
		$Param->productid = 0;

		$retid = $fundHistoryModel->AddItem($Param);
		if(empty($retid)){
			return  buildReturnData('', 'uid为:'.$uid.'插入fundhistory表失败 sql:'.$fundHistoryModel->getLastsql(), 0);
		}
		return  buildReturnData('', 'uid为:'.$uid.'推荐奖励发放完成', 1);
	}

	/**
	 * 判断角色
	 * @param [type] $uid 用户uid
	 */
	public function Judgingrole($uid){
		$memberinfoModel=M('memberinfo');
		$roleModel=M('role');
		$roleid=$memberinfoModel->field('roleid')->where(array('uid'=>$uid))->find();
		if(empty($roleid)){
			return buildReturnData('', $memberinfoModel->getLastsql(), 0);
		}
		$role=$roleModel->where(array('id'=>$roleid['roleid'],'isdel'=>0))->find();
		if(empty($role)){
			return buildReturnData('',  $roleModel->getLastsql(), 0);
		}
		return buildReturnData($role, '', 1);
	}
//----------------------------------------传说中的分隔线---------------------------------------------------------
//----------------------------------------传说中的分隔线---------------------------------------------------------
//判断防重发 begin   所有防重发判断写在这里面便于管理
	/**
	 * 判断角色收益奖励是否已经发放过
	 * 
	 * @param [type] $uid 用户uid
	 * @param [type] $typeid 用户收益类型对应fundhistorytype表里面的id',
	 */
	public function JudgingRepeatReward($uid,$typeid){
		$preventModel=M('prevent');
		$condition=array();
		$condition['uid']=$uid;
		$condition['typeid']=$typeid;
		$condition['to_days(created_at)']=array('exp','=to_days(now())');
		$ret=$preventModel->where($condition)->select();
		if($ret===null){
			return buildReturnData('', '', 1);
		}else{
			return buildReturnData('', $preventModel->getLastsql(), 0);
		}
	}

	/**
	 * 判断角色推荐奖励是否已经发放过
	 * 
	 * @param [type] $uid 用户uid
	 * @param [type] $btuid 被推荐人
	 */
	public function JudgingRepeatReg($uid,$btuid){
		$preventModel=M('prevent');
		$condition=array();
		$condition['uid']=$uid;
		$condition['btuid']=$btuid;
		$condition['to_days(created_at)']=array('exp','=to_days(now())');
		$ret=$preventModel->where($condition)->select();
		if($ret===null){
			return buildReturnData('', '', 1);
		}else{
			return buildReturnData('', $preventModel->getLastsql(), 0);
		}
	}
//判断防重发 end
//----------------------------------------传说中的分隔线---------------------------------------------------------
//----------------------------------------传说中的分隔线---------------------------------------------------------
	/**
	 * 查询推广合伙人下线商户推荐的人的收益  
	 * @param  [type] $uid [description]用户uid
	 */
	public function getjuniormembernew($uid){

		$amout=0;
		$sql='select uid from merchant where tuid = '.$uid.' and verifyStatus=1 and isdel=0';

		$idarr= M()->query($sql);

		if(empty($idarr)){
			return $amout;
		}

		//合伙人推荐的商家推荐的人开始计算奖励

		$time = date('Y-m-d');
		foreach($idarr as $v){
			$sql_2 = "select SUM(interest) as money from usergathering  where tuid={$v['uid']} and (record_type=1 or record_type=2) and substring(repaytime,1,10) = '{$time}'";
			$money= M()->query($sql_2);

			if(0>=count($money)){
				continue;
			}

			$amout+=$money[0]['money'];
		}

		return $amout;
	}
	/**
	 * 查询下线商户推荐的人的收益  //暂时不用
	 * @param  [type] $uid [description]用户uid
	 * @return [type] $yieldrate    [description] 收益比率
	 */
	public function getjuniormemberold($uid,$yieldrate){
		$partnerModel = M('partner');
		$merchantModel=M('merchant');

		//判断是不是推广合伙人
		
		$ret=$partnerModel->where(array('uid'=>$uid,'status'=>1))->find();
		if(empty($ret)){
			return false;
		}
		$sql_1 = 'SELECT btuid from recommend WHERE tuid = '.$uid.' GROUP BY tuid,btuid';
		$idarr= M()->query($sql_1);
		$merchantarr=array();
		foreach($idarr as $v){

			$ret=$merchantModel->where(array('uid'=>$v['btuid'],'verifyStatus'=>1))->find();
			
			if(!empty($ret)){
				$merchantarr[]=$v['btuid'];
			}
		}

		if(0>=count($merchantarr)){
			return false;
		}

		//合伙人推荐的商家推荐的人开始计算奖励
		
		$money= M()->query($sql);
		$arrs=array();
		$amout=0;
		foreach($merchantarr as $v){
			$sql_2 = "select SUM(interest) as money from usergathering where tuid={$v} GROUP BY tuid";
			$money= M()->query($sql_2);

			if(0>=count($money)){
				continue;
			}

			$amout+=round($money[0]['money']*$yieldrate,2);
		}
		return $amout;
	}


	/**
	 * 查询下线uid  //暂时不用
	 * @param  [type] $uid [description]
	 * @return [type]      [description]
	 */
	public function getjuniormember($uid){
		$partnerModel = M('partner');
		$merchantModel=M('merchant');
		$sql_1 = 'SELECT btuid from recommend WHERE tuid = '.$uid.' GROUP BY tuid,btuid';
		//判断是不是推广合伙人
		$idarr= M()->query($sql_1);
		$ret=$partnerModel->where(array('uid'=>$uid,'status'=>1))->find();
		if(empty($ret)){
			return $idarr;
		}
		$merchantarr=array();
		$idarr_1=array();
		$i=0;
		foreach($idarr as $v){
			$idarr_1[$i]=$v;
			$i++;
			$ret=$merchantModel->where(array('uid'=>$v['btuid'],'verifyStatus'=>1))->find();
			if(!empty($ret)){
				$merchantarr[]=$v['btuid'];
			}
		}

		if(0>=count($merchantarr)){
			return $idarr_1;
		}
		//查合伙人推荐的商家推荐的人
		$arrs=array();
		foreach($merchantarr as $v){
			$sql_2 = 'SELECT btuid from recommend WHERE tuid = '.$v.' GROUP BY tuid,btuid';
			$res= M()->query($sql_2);
			if(!empty($res)){
				$arrs[]=$res;
			}
		}
		//组装商家推荐的数组
		foreach($arrs as $v){
			foreach($v as $v1){
				$idarr_1[$i]=$v1;
				$i++;
			}
		}
		return $idarr_1;
	}


	/**
	* 通过uid查询角色信息
	*/
	public function getRoleInfoByAmount($uid,$autoApplyPartner = false){
		
		//日志文件
		$dstlog= LOG_PATH.'changeRole.log';
		
		$roleModel = M("role");
		$memberModel = D('Member');
		$partnerModel = M('partner');		
		$profileModel = new ProfileModel();
		$profileInfo = $profileModel->getProfileInfo($uid);
		$amountInfo = $memberModel->GetMemberColAmountInfo($uid);//获取投资金额
		$currentAsset = $amountInfo['tzamount']+$profileInfo['usableamount'];
		
		$condition = array();
		$condition['category']       = 0;
		$condition['isdel']          = 0;
		$condition['tzamount_begin'] = array('ELT',$currentAsset);
		$condition['tzamount_end']   = array('EGT',$currentAsset);
		

		$conditionx = array();
		$conditionx['uid'] = $uid;
		$conditionx['verifyStatus']  = array('in','0,1');		
		$conditionx['isdel'] = 0;
		
		$partnerInfo = $partnerModel->where($conditionx)->find();
		if($partnerInfo){//如果存在
			if($partnerInfo['puid'] != 0){
				$condition['category'] = 1;	//小S					
			}else{
				$condition['category'] = 2;	//大S				
			}
		}
		$ret = $roleModel->where($condition)->find();

		$msg="查询用户角色SQL：".$roleModel->getLastSql();
		Log::write($msg, Log::DEBUG,'',$dstlog);
		return $ret;

	}

	
	/**
	* 充值,体现引起角色变化触发
	* $param $uid  用户id
	*/
	public function doChangeRole($uid,$autoApplyPartner = false){
		
		$memberModel = D('member');
		$partnerModel = M('partner');
		$roleHistoryModel = M('rolehistory');		
		$memberInfoModel = M('memberinfo');	
		
		$roleInfo = $this->getRoleInfoByAmount($uid,$autoApplyPartner);
		
		if(empty($roleInfo)){
			return buildReturnData('', "用户:{$uid},查询不到相关角色". M("role")->getLastSql(), 0);
		}
		
		$miRole = $memberInfoModel->field('roleid')->where(array('uid'=>$uid))->find();

		if($miRole['roleid'] == $roleInfo['id']){//角色无变化
			return buildReturnData('', '角色无变化'.$memberInfoModel->getLastsql(), 1);
		}	

		if(in_array($roleInfo['roletype'],array(5,6))){//如果成为合伙人

			$partnerInfo  = $partnerModel->where(array('uid'=>$uid,'status'=>1))->find();
			if($partnerInfo){//如果以前有审核过的记录		
				$roleHistoryCond = array();
				$roleHistoryCond['isdel']      = 1;
				$roleHistoryCond['end_at']     = array('exp','now()');
				$roleHistoryCond['deleted_at'] = array('exp','now()');
				$roleHistoryStatus = $roleHistoryModel->where(array('uid'=>$uid,'isdel'=>'0','status'=>'1'))->save($roleHistoryCond);
				if($roleHistoryStatus === false){
					return buildReturnData('', '更新rolehistory表失败', 0);			
				}				

				$addStatus = $this->createRoleHistory($uid,$roleInfo['name'],$roleInfo['id'],1);		if($addStatus === false){
					return buildReturnData('', '添加rolehistory表失败', 0);				
				}

				//更新memberinfo表 
				$cond = array();
				$cond['uid']      = $uid;
				$cond['roleid']   = $roleInfo['id'];
				$memberInfoStatus = $memberInfoModel->save($cond);
				if($memberInfoStatus === false){
					return buildReturnData('', '更新memberinfo表roleid字段失败', 0);
				}					
			}else{
				$partnerData = array();
				$partnerData['uid']        = $uid;
				$partnerData['status']     = 0;
				$partnerData['created_at'] = array('exp','now()');
				$partnerStatus = $partnerModel->add($partnerData);
				if($partnerStatus === false){
					return buildReturnData('', '添加partner表失败', 0);			
				}
				
				$addStatus = $this->createRoleHistory($uid,$roleInfo['name'],$roleInfo['id'],0);		if($addStatus === false){
					return buildReturnData('', '添加rolehistory表失败', 0);				
				}				
			}
		}else{
			//角色变化记录 先删除，再添加
			$roleHistoryCond = array();
			$roleHistoryCond['isdel']      = 1;
			$roleHistoryCond['end_at']     = array('exp','now()');
			$roleHistoryCond['deleted_at'] = array('exp','now()');
			$roleHistoryStatus = $roleHistoryModel->where(array('uid'=>$uid,'isdel'=>'0'))->save($roleHistoryCond);//没有数据，更新失败不处理
			if($roleHistoryStatus === false){
				return buildReturnData('', '更新rolehistory表失败'.$roleHistoryModel->getLastSql(), 0);			
			}	
			
			$addStatus = $this->createRoleHistory($uid,$roleInfo['name'],$roleInfo['id'],1);
			if($addStatus === false){
				return buildReturnData('', '添加rolehistory表失败'.$roleHistoryModel->getLastSql(), 0);				
			}
			
			//更新memberinfo表 
			$cond = array();
			$cond['uid']      = $uid;
			$cond['roleid']   = $roleInfo['id'];
			$memberInfoStatus = $memberInfoModel->save($cond);
			if($memberInfoStatus === false){
				return buildReturnData('', '更新memberinfo表roleid字段失败'.$memberInfoModel->getLastSql(), 0);
			}		
		}

		return buildReturnData('', '角色修改成功', 1);		
	}	
	


	/**
	* 添加rolehistory表
	*/
	public function createRoleHistory($uid,$name,$roleId,$status){
		$roleHistoryModel = M('rolehistory');		
		$roleHistoryAdd = array();
		$roleHistoryAdd['uid']        = $uid;
		$roleHistoryAdd['name']       = $name;
		$roleHistoryAdd['roleid']     = $roleId;
		$roleHistoryAdd['status']     = $status;			
		$roleHistoryAdd['start_at']   = array('exp','now()');
		$roleHistoryAdd['created_at'] = array('exp','now()');
		
		return $roleHistoryModel->add($roleHistoryAdd);
	}
	

	/**
	* 
	* 后台修改角色信息触发
	* $param $roleId_old  旧角色
	* $param $roleId_new  新角色
	* $param $name        角色名字
	*/
	public function editRole($roleId_old,$roleId_new,$name=''){
		
		$memberModel = D('member');
		$memberInfoModel = M('memberinfo');
		$partnerModel = M('partner');
		$roleHistoryModel = M('rolehistory');	
		
		$cond = array();
		$cond['roleid'] = $roleId_old;
		$cond['isdel'] = 0;
		$memberInfoList = $memberInfoModel->where($cond)->select();
		
		//删除rolehistory记录
		$roleHistorySaveSql = "update rolehistory rh inner join memberinfo mi on rh.uid = mi.uid   set rh.isdel = 1,rh.end_at = now(),rh.deleted_at = now() where rh.isdel = 0 and mi.roleid = {$roleId_old} and mi.isdel = 0";		
		$saveStatus = $roleHistoryModel->execute($roleHistorySaveSql);
		if($saveStatus === false){
			return buildReturnData('', '更新rolehistory表失败'.$roleHistoryModel->getLastSql(), 0);	
		}
		
		//增加rolehistory记录
		$roleHistoryAddSql = "";		
		foreach($memberInfoList as $key => $value){
			$roleHistoryAddSql .= "(".$value['uid'].",'".$name."',".$roleId_new.",1,now(),now()),";
		}
		$roleHistoryAddSql = substr($roleHistoryAddSql,0,strlen($roleHistoryAddSql)-1);
		$sql = "insert into rolehistory(uid,name,roleid,status,start_at,created_at)
		values {$roleHistoryAddSql}";
		$addStatus = $roleHistoryModel->execute($sql);
		if($addStatus === false){
			return buildReturnData('', '添加rolehistory表失败'.$roleHistoryModel->getLastSql(), 0);
		}	
		
		//更新memberinfo表
		$memberInfoSaveSql = "update  memberinfo mi set mi.roleid = {$roleId_new} where  mi.roleid = {$roleId_old} and mi.isdel = 0 ";
		$miStatus = $memberInfoModel->execute($memberInfoSaveSql);
		if($miStatus === false){
			return buildReturnData('', '更新memberinfo表失败'.$memberInfoModel->getLastSql(), 0);
		}
		
		return buildReturnData('', '角色修改成功', 1);		
	}	
}
?>