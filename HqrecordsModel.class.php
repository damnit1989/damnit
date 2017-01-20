<?php

class HqrecordsModel extends Model {
	
	
	/**
	 * 单用户可购买限额
	 * @var unknown
	 */
	private $MaxAmountPerUser = 100000;
	/**
	 * 初始年化利率
	 * @var unknown
	 */
	private $BeginYearrate = 7;
	
	private $cache = null;
	
	const CACHE_TIMEOUT = 3600 ;
	
	//全局缓存键值
	const GLO_HQ_CACHE_KEY= 'hq_namespace_key';
	
	/**
	 * 类型 key为键
	 * @var unknown
	 */
	private $TypeArrOfKey = array();
	private $logPath;//日志文件路径
	/**固定缓存key**/
	private $cacheKeyList = array(
								  'hqrecordstype'=>array('hqmodel_hqtypelist',300),
								 );
	
	public function _initialize(){
		parent::_initialize();
		$this->cache = GetCacheObj();
		$this->initType();
		$this->logPath = LOG_PATH . 'Hq_Push_openid'.date('Ymd').'.log';
	}
	
	private function addLog($Msg){    	
		Log::write($Msg, Log::DEBUG,'',$this->logPath);
	}
	/**
	 * 获取活期宝类型
	 */
	private function initType(){
		$TypeList = $this->getTypeList();
		foreach ($TypeList as $v){
			$this->TypeArrOfKey[$v['key']] = array($v['name'], $v['id']);
		}
	}
	
	public function getMaxAmountPerUser(){
		return $this->MaxAmountPerUser;	
	}
	
	
	/**
	 * 检查某人是否还可以购买
	 * 发售额、账户最大限额
	 * @param $uid
	 * @param $amount
	 * @return boolean
	 */
	public function CheckCanBuy($Uid, $Amount){
		//发售额
		$PlatsaleplanModel = new PlatsaleplanModel();
		$Ret = $PlatsaleplanModel->getAmountAndNextStage();
		if(true == $Ret['salesstatus'] || $Ret['status'] == 2){//已售完
			return buildReturnData('', '计划已售完', 0);
		}
		if($Amount > $Ret['balance']){//超出可购买金额
			$infoData = array(
					'canbuy'=>$Ret['balance'],
					'apply'=>$Amount
			);
			return buildReturnData($infoData, "超出可购买金额,可购买金额:{$Ret['balance']},申请金额:{$Amount}", -10000);
		}
		
		//账户最大限额
		//$Ret2 = $this->GetDetailByDate($Uid);
		$profileModel =  new ProfileModel();
		$Ret2 = $profileModel->getCanTakeAmount($Uid);
		$Ret2['total'] = $Ret2['hqAsset'];
		$WillTotal = $Amount+$Ret2['total'];
		if($WillTotal > $this->MaxAmountPerUser){
			$infoData = array(
					'maxlimit'=>$this->MaxAmountPerUser,
					'had'=>$Ret2['total'],
					'apply'=>$Amount,
					'willtotal'=>$WillTotal,
			);
			return buildReturnData($infoData, "超出账户最大限额,最大限额:{$this->MaxAmountPerUser},已有金额:{$Ret2['total']},申请金额:{$Amount},即将持有总金额:{$WillTotal}", -10001);
		}
		
				
		return buildReturnData($Ret, "可购买", 1);
	}

	/**
	 * 计算某一年化利率某两个时间之间应得多少利息
	 * @param unknown $Amount 金额
	 * @param unknown $Yearrate 如7%则输入7
	 * @param unknown $EndDate 2015-05-20
	 * @param string $StartDate 2015-05-20，默认今天
	 */
	public function CalcInterest($Amount, $Yearrate, $EndDate, $StartDate=''){
		if('' == $StartDate){//不填则取今天
			$StartDate = date('Y-m-d');
		}
		
		$Days = getJiangeDays($EndDate, $StartDate);
		$Interest = ($Amount*$Days*$Yearrate)/36500;
		return $Interest;
	}
	
	/**
	 * 某人购买
	 * @return
	 * $buytype  自动购买还是手动购买 默认值为手动 0手动1自动购买
	 */
	public function Buy($Uid,$Amount,$buytype=0){
		
		//要先实名认证				
		$MemberInfoModel = new MemberinfoModel();		
/*		if(!$MemberInfoModel->checkAuthRealName($Uid)){//暂时去掉
			return buildReturnData('', '请先进行实名认证',-11000);
		}*/		
		//是否可购买
		$PlatsaleplanModel = new PlatsaleplanModel();
		$planinfo = $this->CheckCanBuy($Uid, $Amount);
		if(1 != $planinfo['status']){
			return $planinfo;
		}
		
		//账户余额不足
		$Profile = new ProfileModel();
		$profileInfo = $Profile->getProfileInfo($Uid);
		if(floatcmp($profileInfo['usableamount'], $Amount)<0){
			return buildReturnData('', "可用余额不够,购买失败", 0);
		}
		
		//计划相关信息
		$planinfo = $planinfo['data'];		
		//变更购买金额
		$planinfo['salesamount'] = array('exp','salesamount+'.$Amount);
		$condition = array();
		$condition['_string'] = 'salesamount<amount';
		$condition['id'] = $planinfo['id'];
		$savePlat = $PlatsaleplanModel->where($condition)->save($planinfo);
		if(empty($savePlat)){
			return buildReturnData('', '更改销售计划金额失败'.$PlatsaleplanModel->getLastSql(), 0);
		}
		//重新获取计划信息,检查是否超标
		$planinfo = $PlatsaleplanModel->getAmountAndNextStage();
		if(floatcmp($planinfo['salesamount'], $planinfo['amount'])>0){
			return buildReturnData('', "销售计划id{$planinfo['id']},已售金额超标,已售金额{$planinfo['salesamount']},销售计划金额{$planinfo['amount']}", 0);
		}
		//检查是否计划结束
		if($planinfo['balance'] == 0){
			$planinfo['status'] = 2;//销售结束
			$planinfo['endtime'] = array('exp','now()');	
			$savePlat = $PlatsaleplanModel->where(array('id'=>$planinfo['id']))->save($planinfo);
			if(empty($savePlat)){
				return buildReturnData('', '变更销售计划状态失败'.$PlatsaleplanModel->getLastSql(), 0);
			}		
		}		

		
		//购买
	//	$NextRepayDay = getNextRepayDay();
		$applytime = now();	
		$RecordId = $this->createData(1, $Uid, $Amount, $this->BeginYearrate, $applytime,$planinfo['id'],$buytype);
		if(empty($RecordId)){
			return buildReturnData('', '添加活期记录失败'.$this->getLastSql(), 0);
		}
		
		//写profile
		$Profile = new ProfileModel();
		$Ret = $Profile->BuyHq($Uid, $Amount);
		if(!$Ret){
			return buildReturnData('', '修改用户可用余额失败'.$Profile->getLastSql(), 0);
		}
		
		//写memberinfo
		$MemberModel = new MemberModel();
		$Ret = $MemberModel->BuyHq($Uid, $Amount);
		if(!$Ret){
			return buildReturnData('', '修改用户投资金额失败'.$MemberModel->getLastSql(), 0);
		}
		
		//写资金交易记录
		$Fund = new FundhistoryModel();
		$Ret = $Fund->BuyHq($Uid, $Amount, $RecordId);
		if(!$Ret){
			return buildReturnData('', '写资金交易记录失败'.$Fund->getLastSql(), 0);
		}
		
		//添加任务
		$queueModel = new QueueModel();
		$param = array();
		$param['uid'] = $Uid;
		$param['amount'] = $Amount;
		$param['queue_unique_id'] = $queueModel->createUniqueStr(serialize($param));
		$addTask = $queueModel->addTask("@.RpcClient.Rpcclient",'Rpcclient','applyHq',1,$param,0,$param['queue_unique_id']);
		if(empty($addTask)){
			return buildReturnData('', "添加任务失败".$queueModel->getLastSql(), 0);
		}

	//	if($Uid<=100){
			//推送消息
			$first = '购买活期产品成功!';
			$model=M('memberinfo');
			$realname=$model->where('uid='.$Uid)->getField('realname');
			if($buytype==1){
				$first = $realname.' 你好，你的余额已满100元，已自动购买活期产宝!';
			}else{				
				$first = $realname.' 你好，你已购买活期产品成功!';
			}
			$tradeType = '活期宝';
			$wxca=M('wechatuserinfo');
			$openid=$wxca->where('uid='.$Uid)->getField('openid');
			$url='http://qianbao.lubandai.cn/Member/Showfundhistory';
			$LhrecordsModel = new LhrecordsModel();
			$pushparam=$LhrecordsModel->initializeData($openid,$url,$first, $tradeType, $Amount);
			$this->addLog('购买活期宝  uid:'.$Uid.' openid:'.$openid);
			$pushTask = $queueModel->addTask("@.Wechat.Weixin",'Weixin','PushTplMsg',1,$pushparam);
			if(empty($pushTask)){
				return buildReturnData('', "添加活期推送消息任务失败".$queueModel->getLastSql(), 0);
			}
	//	}
		return buildReturnData('', '添加活期记录成功', 1);
	}
	
	/**
	 * 获取某人购买记录
	 * @param unknown $Uid
	 */
	public function GetBuyHistory($Uid){
		$Fund = new FundhistoryModel();
		$Ret = $Fund->GetBuyHqHistory($Uid);
		return buildReturnData($Ret, '获取成功', 1);
	}
	
	/**
	 * 获取某人所有活期某一还款日应得的利息
	 * 假如在此期间不退出,若有退出则会填充endtime并更新此值
	 * @param unknown $Uid
	 * @param unknown $RepayDate 还款日 为空则为下一还款日
	 */
	public function GetShouldIncomeInterest($Uid, $RepayDate=''){
		if('' == $RepayDate){
			$RepayDate = getNextRepayDay();
		}
		
		$Ret = $this->GetIncomeInterestDetail($Uid, $RepayDate);
				
		return buildReturnData($Ret['total'], '获取成功', 1);
	}
	
	/**
	 * 某人收到利息,在定时任务中调用
	 * 还款时
	 * @param unknown $Uid
	 * @param unknown $Amount
	 */
	public function InterestIncome($Uid, $Amount){		
		if(empty($Uid)){
			return buildReturnData('', '用户ID为空', 0);
		}
		if(empty($Amount)){
			return buildReturnData('', '金额为0', 0);
		}
		$uaModel = D('Usergathering');
		//修改records
		$Ret = $this->GetIncomeInterestDetail($Uid);
		if(floatcmp(round($Amount,2), round($Ret['total'],2)) < 0){
			return buildReturnData('', "实际收到的利息({$Amount})小于应得利息({$Ret['total']})", 0);
		}elseif (floatcmp(round($Amount,2), round($Ret['total'],2)) > 0){
			return buildReturnData('', "实际收到的利息({$Amount})大于应得利息({$Ret['total']})", 0);
		}
		$RecordArr = $Ret['detail'];
		$LeftAmount = $Amount;//剩余未瓜分的利息金额
		$FundRemark = "收到活期利息:{$Amount}元,明细:\n";//资金交易记录备注
		foreach($RecordArr as $v){
			if(floatcmp($LeftAmount, 0) <= 0){
				break;
			}
			$cond = array ();
			$cond ['id'] = $v['id'];
			$this->where ( $cond )->find ();
			$InAmount = $this->shouldinterestamount;//该给此记录的金额
			$LeftAmount -= $InAmount;//剩余未瓜分的利息金额
			$this->realinterestamount += $InAmount;
			$this->realinteresttime = array('exp','now()');
			$IsFinish = '本期结清';			
			if($v['status'] == 1){//对于已经体现锁定的记录,则结束
				$this->endtime = array('exp','now()');
				$this->status = 2;//结束
				$this->isdel = 1;
				$IsFinish = '已结清';
			}
			$Ret = $this->save();
			if($Ret === false){
				return buildReturnData('', '修改活期记录失败'.$this->getLastSql(), 0);
			}
			$FundRemark .= "记录ID:{$v['id']},应得利息:{$this->shouldinterestamount},实得利息:{$InAmount},状态:{$IsFinish}\n";
			
			//写回款记录表			
			$uaRet = $uaModel->createData($Uid,1,$v['id'],0,$InAmount);
			if($uaRet === false){
				return buildReturnData('',"回款记录添加失败".$uaModel->getLastSql(), 0);
			}
		}
		//还有钱没有分配完全
		if(floatcmp($LeftAmount, 0)>0){
			$left11 = $Amount-$LeftAmount;
			return buildReturnData('', "系统应派息{$Amount},实派{$left11},未派息完全,派息失败", 0);
		}
		
		//重新整理记录，并将已结清移动记录到历史表
		$Ret = $this->RestoreRecords($RecordArr, $this->getTypeIdByKey('InterestImcomeRestore'));
		if($Ret['status'] != 1){
			return buildReturnData('', '重新整理记录失败'.$this->getLastSql(), 0);
		}
		
		//写profile
		$Profile = new ProfileModel();
		$Ret = $Profile->HqInterestIncome($Uid, $Amount);
		if($Ret === false){
			return buildReturnData('', '修改用户可用余额失败'.$Profile->getLastSql(), 0);
		}
		
		//写memberinfo
		$Mi = new MemberModel();
		$Ret = $Mi->HqInterestIncome($Uid, $Amount);
		if($Ret === false){
			return buildReturnData('', '修改用户总赚取利息失败'.$Mi->getLastSql(), 0);
		}
		
		//写资金交易记录
		$Fund = new FundhistoryModel();
		$Ret = $Fund->HqInterestIncome($Uid, $Amount, $FundRemark);
		if(!$Ret){
			return buildReturnData('', '写资金交易记录失败'.$Fund->getLastSql(), 0);
		}
		
		//添加任务
		$queueModel = new QueueModel();
		$param = array();
		$param['uid'] = $Uid;
		$param['amount'] = $Amount;		
		$param['transfer'] = 0;
		$param['queue_unique_id'] = $queueModel->createUniqueStr(serialize($param));
		$addTask = $queueModel->addTask("@.RpcClient.Rpcclient",'Rpcclient','quiteHq',1,$param,0,$param['queue_unique_id']);
		if(empty($addTask)){
			return buildReturnData('', "添加任务失败".$queueModel->getLastSql(), 0);
		}		
		//@todo:转账指令
		
		return buildReturnData('', '收到利息成功', 1);
	}
	
	
    /**
     * 新版系统派息
     */
    public function InterestIncomeNew($RepayDate = ''){
    	//默认执行当天
    	if(empty($RepayDate)){
    		$RepayDate = date('Y-m-d');
    	}
    	
    	//冻结的活期也派息了,等于当日的执行派息
    	$hqwhere = "where hqrecords.isdel = 0 and hqrecords.status != 2 and  Substring(shouldinteresttime, 1, 10)='{$RepayDate}'";
    	    	
    	$countsql = "select count(1) as num,sum(shouldinterestamount) as shouldinterestamount from hqrecords {$hqwhere}";
     	$todayHq = $this->query($countsql);
    	if($todayHq[0]['num']<=0){
    		return buildReturnData('', '今天没有要执行的活期宝派息', 0);
    	}
    	
    	//今日应派利息总和入库
    	$dividendstatusModel = new DividendstatusModel();
    	$addDividendRet = $dividendstatusModel->createData(1,$todayHq[0]['shouldinterestamount'],$RepayDate);
    	if(empty($addDividendRet)){
    		return buildReturnData('', '每日派息状态监测表入库失败'.$dividendstatusModel->getLastSql(), 0);
    	}
    	    	    	
    	$innersql = "select uid,sum(shouldinterestamount) as shouldinterestamount from hqrecords {$hqwhere} group by uid";
    	
    	//添加任务
    	$queueModel = new QueueModel();
    	$innerRet = $this->query($innersql);
    	foreach ($innerRet as $k=>$v){
    		//总利息不大于0,不进行rpc派息
    		if($v['shouldinterestamount']<=0){
    			continue;
    		}    		
    		$param = array();
    		$param['uid'] = $v['uid'];
    		$param['amount'] = $v['shouldinterestamount'];
    		$param['transfer'] = 0;
    		$param['queue_unique_id'] = $queueModel->createUniqueStr(serialize($param));
    		$addTask = $queueModel->addTask("@.RpcClient.Rpcclient",'Rpcclient','quiteHq',1,$param,0,$param['queue_unique_id']);
    		if(empty($addTask)){
    			return buildReturnData('', "添加任务失败".$queueModel->getLastSql(), 0);
    		}
    	}
    	
    	//批量加钱
    	$sql = "update profile p inner join ($innersql) c on p.uid = c.uid set p.usableamount = p.usableamount+c.shouldinterestamount,p.totalamount = p.totalamount+c.shouldinterestamount";
    	$ret = $this->execute($sql);
    	if(empty($ret)){
    		return buildReturnData('', '批量加钱失败'.$this->getLastSql(), 0);
    	}
    	    	    	
    	//批量更新memberinfo表相关字段
    	$sql2 = "update memberinfo m inner join ($innersql) c on m.uid = c.uid set m.lxamount = m.lxamount+c.shouldinterestamount";
    	$ret = $this->execute($sql2);
    	if(empty($ret)){
    		return buildReturnData('', '批量更新memberinfo表失败'.$this->getLastSql(), 0);
    	}
    	    	
	    //批量插入交易记录
	    $fundsql = "select hqrecords.uid,1,100,sum(shouldinterestamount) as shouldinterestamount,totalamount,tyamount,yxamount,frostamount,usableamount,hqtzamount,dttzamount,lhtzamount,'hqrecords','活期宝收益',now() from hqrecords ". 
	    		   "left join profile on hqrecords.uid = profile.uid ".
	    		   "left join memberinfo on hqrecords.uid = memberinfo.uid ".
	    		   "{$hqwhere} group by uid";
	    $sql3 = "insert into fundhistory(`uid`,`productid`,`typeid`,`income`,`totalamount`,`tyamount`,`yxamount`,`frostamount`,`usableamount`,`hqtzamount`,`dttzamount`,`lhtzamount`,`tname`,`remark`,`created_at`) {$fundsql}";
	    $ret = $this->execute($sql3);
	    if(empty($ret)){
	    	return buildReturnData('', '批量插入fundhistory表失败'.$this->getLastSql(), 0);
	    }
	    
    	//批量插入回款记录表
    	$uasql = "select hqrecords.uid,1,hqrecords.id,0,shouldinterestamount,'{$RepayDate}',now(),now(),recommend.tuid,hqrecords.amount,`memberinfo`.`roleid` as troleid from hqrecords 
    				left join recommend on hqrecords.uid = recommend.btuid 
    				LEFT  JOIN `memberinfo`  on `memberinfo`.uid = `recommend`.`tuid`	
    			  {$hqwhere}";
    	$sql4 = "insert into usergathering(`uid`,`record_type`,`record_id`,`amount`,`interest`,`repaytime`,`created_at`,`updated_at`,`tuid`,`tzamount`,`troleid`) {$uasql}";
    	$ret = $this->execute($sql4);
    	if(empty($ret)){
    		return buildReturnData('', '批量插入usergathering表失败'.$this->getLastSql(), 0);
    	}
    	
    	//批量处理活期宝表相关+加利息,起息日,利率变更
    	//执行时间为当月第几天
    	$j = date('j',strtotime($RepayDate));
    	if($j == 1){//升息日,每月1号    		
    		$sql = "update hqrecords set starttime = shouldinteresttime,realinterestamount = realinterestamount+shouldinterestamount,shouldinteresttime = DATE_ADD(shouldinteresttime,INTERVAL 1 DAY),shouldinterestamount = amount*(yearrate+0.5)/36500,yearrate = yearrate+0.5,".
    			   "endtime = case status when 1 then now() else endtime end,isdel = case status when 1 then 1 else isdel end,status = case status when 1 then 2 else status end  {$hqwhere}";
    	}else{
    		$sql = "update hqrecords set starttime = shouldinteresttime,realinterestamount = realinterestamount+shouldinterestamount,shouldinteresttime = DATE_ADD(shouldinteresttime,INTERVAL 1 DAY),".
    		       "endtime = case status when 1 then now() else endtime end,isdel = case status when 1 then 1 else isdel end,status = case status when 1 then 2 else status end  {$hqwhere}";
    	}
    	
    	$ret = $this->execute($sql);
    	if(empty($ret)){
    		return buildReturnData('', '批量处理活期派息相关字段失败'.$this->getLastSql(), 0);
    	}
    	
    	//变更派息监测表,并且检测实际派的利息
    	$sql = "select sum(interest) as interest from usergathering where record_type = 1 and Substring(repaytime, 1, 10)='{$RepayDate}'";
        $todayTotalInterest = $this->query($sql);
    	if(floatcmp($todayTotalInterest[0]['interest'], $todayHq[0]['shouldinterestamount']) != 0){
    		return buildReturnData('', '理论派息与实际派息不相等', 0);
    	}
    	
    	$item = $dividendstatusModel->changeStatus($addDividendRet,$todayHq[0]['shouldinterestamount'],1);
    	if($item === false){
    		return buildReturnData('', '变更派息监测表失败'.$dividendstatusModel->getLastSql(), 0);
    	}    	
    	return buildReturnData('', '批量处理活期派息成功', 1);
    }
	
	/**
	 * //重新整理记录，并将已结清移动记录到历史表
	 * //@param unknown $RecordArr 产生变化的记录集
	 * //@param unknown $TypeId 重新整理的原因
	 * @备注 派息时调用,重新整理记录,
	 * 若不是升息日,则重新生成下一期的利息等相关数据,
	 * 若是升息日,则升息后计算
	 */
	private function RestoreRecords($RecordArr, $TypeId){
		foreach($RecordArr as $v){			
			//当期还款日
			$nextRepayDay = $v['shouldinteresttime'];
			//第几天
			$j = date('j',strtotime($nextRepayDay));
			//下一期还款日
			$newNextRepayDay = getNextRepayDay($nextRepayDay);
						
			$info = $this->find($v['id']);
			if($info['status'] == 2 && $info['isdel'] == 1){//结束掉的记录,则
				continue;
			}
			if($j == 1){//升息日,升息日改为每月1号
				$info['yearrate'] = $info['yearrate']+0.5;
				$info['yearrate'] = $info['yearrate']>12?12:$info['yearrate'];//最高不超过12			
			}			
			$info['starttime'] = $nextRepayDay;
			$info['shouldinteresttime'] = $newNextRepayDay;
			$info['shouldinterestamount'] = $this->CalcInterest($info['amount'], $info['yearrate'], $newNextRepayDay,$nextRepayDay);
		//	$info['status'] = 0;
			$save = $this->where(array('id'=>$info['id']))->save($info);
			if($save === false){
				return  buildReturnData('', '重新计算失败'.$this->getLastSql(), 0);
			}
		}
		return  buildReturnData('', '处理成功', 1);
		//将已结清移动记录到历史表 可能没有记录，所以无需看返回值
	//	$this->MoveComplitedToHistory();
	}
	
	/**
	 * 将已结清移动记录到历史表
	 */
	private function MoveComplitedToHistory(){
		$cond = array ();
		//$cond ['status'] = 3;
		$cond ['status'] = 2;//已结清,本期结清
		$cond ['status'] = 1;//真正结束
		$List = $this->where ( $cond )->select ();
		if(empty($List)){
			return true;
		}
		
		foreach($List as $v){
			//历史表新纪录
			$TabNo = $this->getTabNo($v['uid']);
			$Model = M("{$TabNo}");
			$Ret = $Model->add($v);
			if(!$Ret){
				continue;
			}
			
			//删原记录
			$this->where("id='{$v['id']}'")->delete();
		}
		return true;
	}
	
	/**
	 * 获取某人某一结息日的结息明细
	 * @param unknown $Uid
	 * @param unknown $RepayDate 默认今天
	 */
	public function GetIncomeInterestDetail($Uid, $RepayDate=''){
		$Ret = array('total'=>0, 'detail'=>array());
		
		if('' == $RepayDate){//不填则取今天
			$RepayDate = date('Y-m-d');
		}
		
		$cond = array ();
		$cond ['uid'] = $Uid;
		$cond ['isdel'] = DB_UNDEL;
		$cond ['_string'] = "Substring(shouldinteresttime, 1, 10)='{$RepayDate}' and status!=2";
		$List = $this->where ( $cond )->select ();
		if(empty($List)){
			return $Ret;
		}
		
		$total = 0;
		foreach($List as $v){
			$total += $v['shouldinterestamount'];			
		}
		$Ret['total'] = $total;
		$Ret['detail'] = $List;
		return $Ret;
	}
	
	/**
	 * 升息日，升息，全体狂欢(退休)
	 */
	public function YearrateGrow(){
		
	}
	
	/**
	 * 获取某人当前资金升息明细
	 * @param unknown $Uid
	 */
	public function GetYearrateGrowDetail($Uid){
		
	}
	

	
	/**
	 * 获取某人某时间点账户余额及组成明细
	 * @param unknown $Uid
	 * @param unknown $Date
	 */
	public function GetDetailByDate($Uid, $Date=''){
		if('' == $Date){//不填则取今天
			$Date = date('Y-m-d');
		}
		
		$Ret = array(
			'total'=>0,
			'detail'=>array(
				
			),
		);
		
		$TabNo = $this->getTabNo($Uid);//分表序号
		$PreRepayDate = '';//上一结息日
		$Fields = "amount, yearrate, starttime, shouldinterestamount";
		if($Date > $PreRepayDate){//查现在
			$BeginTime = "{$Date} 23:59:59";
			$sql = "select {$Fields} from hqrecords where starttime<='{$BeginTime}'";
		}else{//查历史 利息已全部结清才会进历史表
			$BeginTime = "{$Date} 23:59:59";
			$EndTime = "{$Date} 00:00:01";
			$sql = "select {$Fields} from hqrecords{$TabNo} where starttime<='{$BeginTime}' and endtime>='{$EndTime}'";
		}
		$List = $this->query($sql);
		
		$total = 0;
		foreach($List as $v){
			$total += $v['shouldinterestamount'];
		}
		
		$Ret['total'] = $total;
		$Ret['detail'] = $List;
		
		return $Ret;
	}
	
	/**
	 * 通过用户Id获取记录历史表编号
	 * @param unknown $Uid
	 */
	private function getTabNo($Uid){
		$TabNo = $Uid % 4;
		return "0{$TabNo}";
	}
	
	/**
	 * 获取某人某利率的钱列表
	 * @param unknown $Uid
	 * @param unknown $Yearrate
	 */
	public function GetByYearrate($Uid, $Yearrate){
		$cond = array ();
		$cond ['uid'] = $Uid;
		$cond ['yearrate'] = $Yearrate;
		$cond ['isdel'] = DB_UNDEL;
		return $this->where ( $cond )->select ();
	}
	
	/**
	 * 获取产品对应债权记录
	 * @param $RecordsId 活期RecordsId
	 */
	public function getShowCredit($RecordsId){
		//取得相关信息
		$info = $this->getById($RecordsId);
		$uid = $info['uid'];
		$amount = $info['amount'];
		$recordtype = 1;//或者$info['schemeid'];
		//准备
		import("@.RpcClient.Rpcclient");
		$client = new Rpcclient();
		
		//调用鲁班贷RPC接口,获取对应债权
		$Ret = $client->getInvestCreditInfo($uid,$amount,$recordtype,$RecordsId);
		if($Ret['status'] != 1){
			return buildReturnData('', "RPC处理失败,".$Ret['info'], 0);
		}
		$Ret['data']['lbd_borrow_detail'] = json_decode(base64_decode($Ret['data']['lbd_borrow_detail']),true);
		return buildReturnData($Ret['data'], "获取成功", 1);
	}

	/**
	 * 获取产品对应债权记录
	 * @param $recordsIds 活期RecordsId集合
	 */
	public function getShowCredits($recordsIds){
		//取得相关信息
		if(!is_array($recordsIds)){
			return buildReturnData('', "非法格式", 0);
		}
		$amountList = array();
		foreach ($recordsIds as $k=>$v){
			$info = $this->getById($v);
			$uid = $info['uid'];
			array_push($amountList, $info['amount']);
		}
		$recordtype = 1;//或者$info['schemeid'];
		//准备
		import("@.RpcClient.Rpcclient");
		$client = new Rpcclient();
		//调用鲁班贷RPC接口,获取对应债权
		$Ret = $client->getInvestCreditInfos($uid,$amountList,$recordtype,$recordsIds);
		if($Ret['status'] != 1){
			return buildReturnData('', "RPC处理失败,".$Ret['info'], 0);
		}
		foreach ($Ret['data'] as $k=>&$v){
			$v['lbd_borrow_detail'] = json_decode(base64_decode($v['lbd_borrow_detail']),true);
		}
		unset($v);		
		return buildReturnData($Ret['data'], "获取成功", 1);
	}
	
	/**
	 * 取类型列表
	 */
	public function getTypeList(){
		$ret = array();
		if($this->cache->get($this->cacheKeyList['hqrecordstype'][0])){
			$ret = $this->cache->get($this->cacheKeyList['hqrecordstype'][0]);
		}else{
			$model = D('hqrecordstype');
			$condition = array();
			$condition['isdel'] = 0;
			$ret = $model->where($condition)->select();
			$this->cache->set($this->cacheKeyList['hqrecordstype'][0],$ret,$this->cacheKeyList['hqrecordstype'][1]);
		}
		return $ret;
	}
	
	/**
	 * 通过键取类型Id
	 * @param unknown $Key
	 */
	public function getTypeIdByKey($Key){
		return $this->TypeArrOfKey[$Key][1];
	}
	
	/**
	 *获取参与计划的列表记录 
	 *@param $fill 扩充字段
	 */
	public function getHqPlanList($opt = array(),$start=0,$limit=0,$fill=false){
		$result = array();
		$list = array();
		if($opt){
			$this->where($opt);
		}
		if($limit){
			$this->limit($start,$limit);
		}
		$this->order('created_at Desc');
		$list = $this->select();
		if($opt){
			$total = $this->where($opt)->count();	
		}else{
			$total = $this->count();
		}
		$result['data'] = $list;
		$result['total'] = $total;
		return 	$result;
	}
	
	/**
	 *获取某人正在参加的计划列表
	 *@param $uid 用户
	 *@param return array
	 */
	public function getCurrentList($uid){
		//正常状态的计划，锁定的和结束的都忽略
		$ret = array();
		$cachekey = $this->getHqlistKey();//key
		if($this->cache->get($cachekey) !== false){
			return $this->cache->get($cachekey);
		}
		$sql = "select * from hqrecords where uid = {$uid} and status = 0  and isdel = 0  order by yearrate asc,applytime desc";
		$ret =  $this->query($sql);
		$usergaModel = new UsergatheringModel();
		foreach ($ret  as $k=>&$v){
			//利息
			$v['earning'] = $usergaModel->getEarning(1,$v['id']);
			$v['earning'] = $v['earning']?$v['earning']:0;
			$v['applytime'] = date('Y-m-d',strtotime($v['applytime']));
			$v['created_at'] = date('Y-m-d',strtotime($v['created_at']));
		}
		unset($v);
		
		//利率相同的合并,同一天购买的也合并
		$result = array();
		foreach ($ret as $k=>$v){
		  $result[$v['yearrate']][] = $v;
		}
		$result2 = array();
		foreach ($result as $k=>$v){
			$var = array();
			$var['title'] = $k;
			$var['list'] = $v;
			$result2[] = $var;
		}
		
		foreach ($result2 as $k=>&$v){
			$list = array();
			foreach ($v['list'] as $k1=>$v1){
				$list[$v1['applytime']][] = $v1;
			}
			$list2 = array();
			foreach ($list as $k2=>$v2){
				$ids = array();
				$total = 0;
				foreach ($v2 as $k4=>$v4){
					$total+=$v4['amount'];
					array_push($ids, $v4['id']);
				}
				$ids = implode('-', $ids);				
				$var = array();
				$var['title'] = $k2;
				$var['ids'] = $ids;
				$var['yearrate'] = $v2[0]['yearrate'];
				$var['applytime'] = $v2[0]['applytime'];
				$var['created_at'] = $v2[0]['created_at'];
				$var['uid'] = $v2[0]['uid'];
				$var['typeid'] = $v2[0]['typeid'];
				$var['amount'] = $total;
				$list2[] = $var;
			}
			$v['list'] = $list2;
		}
		unset($v);
		//$cachekey = $this->getHqlistKey($uid);//key
		$this->cache->set($cachekey,$result2,self::CACHE_TIMEOUT);//5分钟
		return $result2;
	}
	
	/**
	 * 获取某天系统内活期计划还款的组成元素
	 * @param $RepayDate 某天
	 * return array
	 */
	public function getHqElement($RepayDate=''){
		if('' == $RepayDate){//不填则取今天
			$RepayDate = date('Y-m-d');
		}
		$sql = "select uid,sum(amount) as amount,sum(shouldinterestamount) as shouldinterestamount from hqrecords where status != 2 and isdel = 0 and Substring(shouldinteresttime, 1, 10)='{$RepayDate}' group by uid";
		return $this->query($sql);		
	}
	
	/**
	 * 申请提现,活期记录相关处理流程
	 * @param $uid 用户id
	 * @param $applyamount 申请金额
	 * @param $takeid $takeid
	 * @return  array
	 */
	public function doTakeApply($uid,$applyamount,$takeid){
		
		//2015-10-28
	    if($applyamount%100 !=0 || $applyamount<=0){
        	 return buildReturnData('',"活期转现必须为100的整数倍", 0);
        }
        $Profile = new ProfileModel();
        //判断活期是否足够
        //无锁定期了。
    	$sql = "select sum(amount) as amount from hqrecords where uid={$uid} and status = 0 and isdel = 0";
    	$hqamountRet = $this->query($sql);
    	$hqamount = $hqamountRet[0]['amount'];
    	if(floatcmp($applyamount, $hqamount)>0){
    		return buildReturnData('', "超过可返现活期宝金额,应从活期宝返现{$applyamount},当前活期宝只有{$hqamount}", 0);
    	}
		
		$uaModel = new UsergatheringModel();
		//获取用户的未锁定的活期记录 锁定的和结束的都忽略
		//$sql = "select * from hqrecords where applytime<=DATE_SUB(now(),INTERVAL 7 DAY) and uid = {$uid} and status = 0  and isdel = 0  order by yearrate asc,amount asc,created_at desc";
		//无锁定期了
		$sql = "select * from hqrecords where uid = {$uid} and status = 0  and isdel = 0  order by yearrate asc,amount asc,created_at desc";
		$ret = $this->query($sql);
		$leftAmount = $applyamount;
		foreach ($ret as $k=>$v){
			$canTakeAmount = $v['amount'];
			if(floatcmp($leftAmount, $canTakeAmount)==0){//结束
				//锁定记录
				$saveRet = $this->lockRecord($v['id'], $takeid, $leftAmount);
				if($saveRet['status'] != 1){
					return buildReturnData('', '变更活期记录失败'.$saveRet['info'], 0);
				}
				
				//写回款记录表
				$uaRet = $uaModel->createData($uid,1,$v['id'],$canTakeAmount,0);
				if($uaRet === false){
					return buildReturnData('',"本金回款记录添加失败".$uaModel->getLastSql(), 0);
				}
				
				$leftAmount = $leftAmount-$canTakeAmount;
				
				break;
			}
			if(floatcmp($leftAmount, $canTakeAmount)<0){//结束				
				//锁定记录
				$saveRet = $this->lockRecord($v['id'], $takeid, $leftAmount,true);
				if($saveRet['status'] != 1){
					return buildReturnData('', '变更活期记录失败'.$saveRet['info'], 0);
				}
				
				//写回款记录表
				$uaRet = $uaModel->createData($uid,1,$v['id'],$leftAmount,0);
				if($uaRet === false){
					return buildReturnData('',"本金回款记录添加失败".$uaModel->getLastSql(), 0);
				}
				
				$leftAmount = 0;
				
				break;
			}	
			if(floatcmp($leftAmount, $canTakeAmount)>0){//继续循环
				//更改记录状态等字段
				$saveRet = $this->lockRecord($v['id'], $takeid, $canTakeAmount);
				if($saveRet['status'] != 1){
					return buildReturnData('', '变更活期记录失败'.$saveRet['info'], 0);
				}
				
				//写回款记录表
				$uaRet = $uaModel->createData($uid,1,$v['id'],$canTakeAmount,0);
				if($uaRet === false){
					return buildReturnData('',"本金回款记录添加失败".$uaModel->getLastSql(), 0);
				}
				
				$leftAmount = $leftAmount-$canTakeAmount;
			}
		}
		if(floatcmp($leftAmount,0)>0){//还有剩余金额,提现失败
			return buildReturnData('', "提现金额为{$applyamount}超过可提现金额{$leftAmount}元,提现处理失败", 0);
		}
				
		//写profile		
		$Ret = $Profile->HqAmountIncome($uid, $applyamount);
		if($Ret === false){
			return buildReturnData('', '修改用户可用余额失败'.$Profile->getLastSql(), 0);
		}
		
		//写memberinfo
		$Mi = new MemberModel();
		$Ret = $Mi->HqAmountIncome($uid, $applyamount);
		if($Ret === false){
			return buildReturnData('', '修改用户投资资金字段失败'.$Mi->getLastSql(), 0);
		}
		
		//写资金交易记录
		$Fund = new FundhistoryModel();
		$Ret = $Fund->HqAmountIncome($uid, $applyamount);
		if(!$Ret){
			return buildReturnData('', '写资金交易记录失败'.$Fund->getLastSql(), 0);
		}
		
		
		//添加任务
		$queueModel = new QueueModel();
		$param = array();
		$param['uid'] = $uid;
		$param['amount'] = $applyamount;
		$param['transfer'] = 1;
		$param['queue_unique_id'] = $queueModel->createUniqueStr(serialize($param));
		$addTask = $queueModel->addTask("@.RpcClient.Rpcclient",'Rpcclient','quiteHq',1,$param,0,$param['queue_unique_id']);
		if(empty($addTask)){
			return buildReturnData('', "添加任务失败".$queueModel->getLastSql(), 0);
		}
						
		return buildReturnData('', '处理成功', 1);						
	}
	
	/**
	 * 提现审核成功后,调用此方法,再做提现(暂时废弃,流程直接放入锁定计划里)
	 */
	public function doTakeApplySuc($takeid){
		//赛选中锁定的活期计划,找出是否有需要新生成的活期记录
		$sql = "select * from hqrecords where takeid={$takeid} and status = 1";
		$ret = $this->query($sql);
		if(empty($ret)){//不存在需要重新生成的活期记录
			return true;
		}
		$info = $ret[0];
		//生成新记录
		$newApplyAmount = $info['amount']-$info['takeamount'];
		$RecordId = $this->createData(8, $info['uid'], $newApplyAmount, $info['yearrate'], $info['applytime']);
		if(empty($RecordId)){
			return buildReturnData('', '添加活期记录失败'.$this->getLastSql(), 0);
		}		
		return buildReturnData($RecordId, '生成活期记录成功', 1);
	}
	
	/**
	 * 提现锁定某活期记录
	 * @param $id 活期计划id
	 * @param $takeid int
	 * @param $takeamount decimal
	 * @param $needCreateNew boolean 是否需要生成新记录,提现申请时判断
	 * @return mix
	 */
	private function lockRecord($id,$takeid,$takeamount,$needCreateNew=false){
		$ret = $this->find($id);
		
		if($ret['status'] == 1){
			return buildReturnData('', '锁定活期记录失败,已被锁定', 0);
		}
		$data = array();
		$data['takeapplytime'] = date('Y-m-d');
		$data['takeamount'] = $takeamount;
		$data['takeid'] = $takeid;
		$data['status'] = 1;//锁定
		//重新计算利息
		$NextRepayDay = getNextRepayDay();
		$data['shouldinterestamount'] = $this->CalcInterest($ret['amount'], $ret['yearrate'], now(),$ret['starttime']);
		$saveRet = $this->where(array('id'=>$id))->save($data);
		if(empty($saveRet)){
			return buildReturnData('', '锁定活期记录失败', 0);
		}
		//判断是否需要生成新记录
		if($needCreateNew == true){
			//生成新记录
			$newApplyAmount = $ret['amount']-$takeamount;
			if($newApplyAmount>0){
				$RecordId = $this->createData(8, $ret['uid'], $newApplyAmount, $ret['yearrate'], $ret['applytime']);
				if(empty($RecordId)){
					return buildReturnData('', '提现申请,添加活期记录失败'.$this->getLastSql(), 0);
				}
			}
		}
		return buildReturnData($RecordId, '锁定活期记录成功', 1);
	}
	
	/**
	 * 支付锁定某活期记录
	 * @param $id 活期计划id
	 * @param $exchangeid int
	 * @param $exchangeamount decimal
	 * @param $needCreateNew boolean 是否需要生成新记录,提现申请时判断
	 * @return mix
	 */
	private function exchangeLockRecord($id,$exchangeid,$exchangeamount,$needCreateNew=false){
		$ret = $this->find($id);
		
		if($ret['status'] == 1){
			return buildReturnData('', '锁定活期记录失败,已经被锁定', 0);
		}
		
		$data = array();
		$data['exchangeapplytime'] = date('Y-m-d');
		$data['exchangeamount'] = $exchangeamount;
		$data['exchangeid'] = $exchangeid;
		$data['status'] = 1;//锁定
		//重新计算利息
		$NextRepayDay = getNextRepayDay();
		$data['shouldinterestamount'] = $this->CalcInterest($ret['amount'], $ret['yearrate'], now(),$ret['starttime']);
		$saveRet = $this->where(array('id'=>$id))->save($data);
		if(empty($saveRet)){
			return buildReturnData('', '锁定活期记录失败', 0);
		}
		//判断是否需要生成新记录
		if($needCreateNew == true){
			//生成新记录
			$newApplyAmount = $ret['amount']-$exchangeamount;
			if($newApplyAmount>0){
				$RecordId = $this->createData(8, $ret['uid'], $newApplyAmount, $ret['yearrate'], $ret['applytime']);
				if(empty($RecordId)){
					return buildReturnData('', '支付申请,添加活期记录失败'.$this->getLastSql(), 0);
				}
			}
		}
		return buildReturnData($RecordId, '锁定活期记录成功', 1);
	}
	
	/**
	 * 生成活期记录
	 */
	private function createData($typeid,$uid,$amount,$yearrate,$applytime,$platsaleid=0,$buytype = 0){
		$Data = array();
		$NextRepayDay = getNextRepayDay();
		$Data['typeid'] = $typeid;
		$Data['uid'] = $uid;
		$Data['amount'] = $amount;
		$Data['yearrate'] = $yearrate;
		$Data['applytime'] = $applytime;
		$Data['starttime'] = array('exp','now()');
		$Data['shouldinterestamount'] = $this->CalcInterest($amount, $yearrate, $NextRepayDay);
		$Data['shouldinteresttime'] = $NextRepayDay;
		$Data['status'] = 0;
		$Data['isdel'] = DB_UNDEL;
		$Data['created_at'] = array('exp','now()');
		$Data['updated_at'] = array('exp','now()');
		$Data['platsaleid'] = $platsaleid;
		$Data['buytype'] = $buytype;
		//清除活期相关据安居缓存空间
		$this->clearHqCacheNamespace($uid);
		return $this->add($Data);
	}
	
	/**
	 * 获取参与活期计划的系统总额
	 * @param $condition
	 * @return decimal || int
	 */
	public function getTotalApplyAmount($condition = array()){
		$condition['typeid'] = 1;
		$total = $this->where($condition)->sum('amount');
		return $total;	
	}
	
	
	/**
	 * 转账支付,活期记录相关处理流程
	 * @param $uid 用户id
	 * @param $applyamount 申请金额
	 * @param $exchangeid 转账支付id
	 * @return  array
	 */
	public function doExchangeApply($uid,$applyamount,$exchangeid){
		$uaModel = new UsergatheringModel();
		//获取用户的未锁定的活期记录 锁定的和结束的都忽略
		$sql = "select * from hqrecords where uid = {$uid} and status = 0  and isdel = 0  order by yearrate asc,amount asc,created_at desc";
		$ret = $this->query($sql);
		$leftAmount = $applyamount;
		foreach ($ret as $k=>$v){
			$canTakeAmount = $v['amount'];
			if(floatcmp($leftAmount, $canTakeAmount)==0){//结束
				//锁定记录
				$saveRet = $this->exchangeLockRecord($v['id'], $exchangeid, $leftAmount);
				if($saveRet['status'] != 1){
					return buildReturnData('', '变更活期记录失败'.$saveRet['info'], 0);
				}
				
				//写回款记录表
				$uaRet = $uaModel->createData($uid,1,$v['id'],$canTakeAmount,0);
				if($uaRet === false){
					return buildReturnData('',"本金回款记录添加失败".$uaModel->getLastSql(), 0);
				}
				
				$leftAmount = $leftAmount-$canTakeAmount;
				
				break;
			}
			if(floatcmp($leftAmount, $canTakeAmount)<0){//结束
				//锁定记录
				$saveRet = $this->exchangeLockRecord($v['id'], $exchangeid, $leftAmount,true);
				if($saveRet['status'] != 1){
					return buildReturnData('', '变更活期记录失败'.$saveRet['info'], 0);
				}
				
				//写回款记录表
				$uaRet = $uaModel->createData($uid,1,$v['id'],$leftAmount,0);
				if($uaRet === false){
					return buildReturnData('',"本金回款记录添加失败".$uaModel->getLastSql(), 0);
				}
				
				$leftAmount = 0;
				
				break;
			}
			if(floatcmp($leftAmount, $canTakeAmount)>0){//继续循环
				//更改记录状态等字段
				$saveRet = $this->exchangeLockRecord($v['id'], $exchangeid, $canTakeAmount);
				if($saveRet['status'] != 1){
					return buildReturnData('', '变更活期记录失败'.$saveRet['info'], 0);
				}
				
				//写回款记录表
				$uaRet = $uaModel->createData($uid,1,$v['id'],$canTakeAmount,0);
				if($uaRet === false){
					return buildReturnData('',"本金回款记录添加失败".$uaModel->getLastSql(), 0);
				}
				
				$leftAmount = $leftAmount-$canTakeAmount;
			}
		}
		if(floatcmp($leftAmount,0)>0){//还有剩余金额,交易申请失败
			return buildReturnData('', "支付交易申请金额为{$applyamount}超过可支付申请金额{$leftAmount}元,交易申请处理失败", 0);
		}

		//写profile
		$Profile = new ProfileModel();
		$Ret = $Profile->HqAmountIncome($uid, $applyamount);
		if($Ret === false){
			return buildReturnData('', '修改用户可用余额失败'.$Profile->getLastSql(), 0);
		}

		//写memberinfo
		$Mi = new MemberModel();
		$Ret = $Mi->HqAmountIncome($uid, $applyamount);
		if($Ret === false){
			return buildReturnData('', '修改用户投资资金字段失败'.$Mi->getLastSql(), 0);
		}

		//写资金交易记录
		$Fund = new FundhistoryModel();
		$Ret = $Fund->HqAmountIncome($uid, $applyamount);
		if(!$Ret){
			return buildReturnData('', '写资金交易记录失败'.$Fund->getLastSql(), 0);
		}


		//添加任务
		$queueModel = new QueueModel();
		$param = array();
		$param['uid'] = $uid;
		$param['amount'] = $applyamount;
		$param['transfer'] = 1;		
		$param['queue_unique_id'] = $queueModel->createUniqueStr(serialize($param));		
		$addTask = $queueModel->addTask("@.RpcClient.Rpcclient",'Rpcclient','quiteHq',1,$param,0,$param['queue_unique_id']);
		if(empty($addTask)){
			return buildReturnData('', "添加任务失败".$queueModel->getLastSql(), 0);
		}

		return buildReturnData('', '处理成功', 1);
	}
	
	/**
	 * 获取某销售计划的购买记录
	 * @param $id 销售计划id
	 * @return array
	 */
	public function getHqListByPlatsaleId($opt = array(),$start=0,$order,$limit=0){	
		$result = array();
		$list = array();
		foreach ($opt as $k=>$v){
			if(empty($opt[$k])){
				unset($opt[$k]);
			}
		}
		$total = $this->where($opt)->count();
		if($total){
			$this->where($opt);
			$this->order($order);
			if($limit!=0){
				$this->limit($start,$limit);
			}
			$list = $this->select();
		}else{
			$list = array();
			$total = 0;
		}
		$result['rows'] = $list;
		$result['total'] = $total;
		$result['sql'] = $this->getLastSql();
		return 	$result;
	}
	
	/**
	 * 获取投资金额地域分布
	 * 北京 上海 广东 天津 浙江 江苏 福建
	 * 
	 */
	public function getinvestarea(){
		$data = array();
        $condition = array('北京','上海','广东','天津','浙江','江苏','福建');
        foreach($condition as $key => $val){
            $res = $this->query("SELECT SUM(amount) as amount from hqrecords where typeid = 1 AND uid IN (SELECT id from member WHERE phoneAreaProvince = '{$val}')");
            foreach ($res as $key => $value) {
            	$data[] = $value['amount'] ? $value['amount'] : 0;
            }  
        }
        return $data;
	}

	/**
	 * 获取投资 金额 人次 的地域分布
	 * 北京 上海 广东 天津 浙江 江苏 福建
	 * 
	 */
	public function getInvestTimeArea(){
		$data = array();
        $condition = array('北京','上海','广东','天津','浙江','江苏','福建');
        foreach($condition as $key => $val){
            $res = $this->query("SELECT count(hq.amount) AS times, SUM(hq.amount) AS amount, m.phoneAreaProvince AS Province from hqrecords AS hq LEFT JOIN member AS m ON m.id=hq.uid where typeid = 1 AND uid IN (SELECT id from member WHERE phoneAreaProvince = '{$val}')");
            foreach ($res as $key => $value) {
            	$data[] = $value;
            }  
        }
        return $data;
	}

	/**
	 * 获取参与活期计划的总投资次数
	 * @param $condition
	 * @return decimal || int
	 */
	public function getTotalInvestTime($condition = array()){
		$condition['typeid'] = 1;
		$total = $this->where($condition)->count();
		return $total;	
	}
	
	/**
	 * 活期转零钱,活期记录相关处理流程
	 * @param $uid 用户id
	 * @param $applyamount 转让金额
	 * @return  array
	 */
	public function doReceiveApply($uid,$applyamount){
		
		$uaModel = new UsergatheringModel();		
		$receiveModel = new HqreceiveModel();
		
		//插入套现记录
		$receiveid = $receiveModel->addItem($uid, $applyamount);
		
		//获取用户的未锁定的活期记录 锁定的和结束的都忽略
		//$sql = "select * from hqrecords where applytime<=DATE_SUB(now(),INTERVAL 7 DAY) and uid = {$uid} and status = 0  and isdel = 0  order by yearrate asc,amount asc,created_at desc";
		//无锁定期了
		$sql = "select * from hqrecords where uid = {$uid} and status = 0  and isdel = 0  order by yearrate asc,amount asc,created_at desc";
		$ret = $this->query($sql);
		$leftAmount = $applyamount;
		foreach ($ret as $k=>$v){
			$canTakeAmount = $v['amount'];
			if(floatcmp($leftAmount, $canTakeAmount)==0){//结束
				//锁定记录
				$saveRet = $this->receiveLockRecord($v['id'], $receiveid, $leftAmount);
				if($saveRet['status'] != 1){
					return buildReturnData('', '变更活期记录失败'.$saveRet['info'], 0);
				}
				
				//写回款记录表
				$uaRet = $uaModel->createData($uid,1,$v['id'],$canTakeAmount,0);
				if($uaRet === false){
					return buildReturnData('',"本金回款记录添加失败".$uaModel->getLastSql(), 0);
				}
				
				$leftAmount = $leftAmount-$canTakeAmount;
				
				break;
			}
			if(floatcmp($leftAmount, $canTakeAmount)<0){//结束				
				//锁定记录
				$saveRet = $this->receiveLockRecord($v['id'], $receiveid, $leftAmount,true);
				if($saveRet['status'] != 1){
					return buildReturnData('', '变更活期记录失败'.$saveRet['info'], 0);
				}
				
				//写回款记录表
				$uaRet = $uaModel->createData($uid,1,$v['id'],$leftAmount,0);
				if($uaRet === false){
					return buildReturnData('',"本金回款记录添加失败".$uaModel->getLastSql(), 0);
				}
				
				$leftAmount = 0;
				
				break;
			}	
			if(floatcmp($leftAmount, $canTakeAmount)>0){//继续循环
				//更改记录状态等字段
				$saveRet = $this->receiveLockRecord($v['id'], $receiveid, $canTakeAmount);
				if($saveRet['status'] != 1){
					return buildReturnData('', '变更活期记录失败'.$saveRet['info'], 0);
				}
				
				//写回款记录表
				$uaRet = $uaModel->createData($uid,1,$v['id'],$canTakeAmount,0);
				if($uaRet === false){
					return buildReturnData('',"本金回款记录添加失败".$uaModel->getLastSql(), 0);
				}
				
				$leftAmount = $leftAmount-$canTakeAmount;
			}
		}
		if(floatcmp($leftAmount,0)>0){//还有剩余金额,提现失败
			return buildReturnData('', "套现金额为{$applyamount}超过可套现金额{$leftAmount}元,套现处理失败", 0);
		}
				
		//写profile
		$Profile = new ProfileModel();
		$Ret = $Profile->HqAmountIncome($uid, $applyamount);
		if($Ret === false){
			return buildReturnData('', '修改用户可用余额失败'.$Profile->getLastSql(), 0);
		}
		
		//写memberinfo
		$Mi = new MemberModel();
		$Ret = $Mi->HqAmountIncome($uid, $applyamount);
		if($Ret === false){
			return buildReturnData('', '修改用户投资资金字段失败'.$Mi->getLastSql(), 0);
		}
		
		//写资金交易记录
		$Fund = new FundhistoryModel();
		$Ret = $Fund->HqAmountIncome($uid, $applyamount);
		if(!$Ret){
			return buildReturnData('', '写资金交易记录失败'.$Fund->getLastSql(), 0);
		}
		
		
		//添加任务
		$queueModel = new QueueModel();
		$param = array();
		$param['uid'] = $uid;
		$param['amount'] = $applyamount;
		$param['transfer'] = 1;
		$param['queue_unique_id'] = $queueModel->createUniqueStr(serialize($param));
		$addTask = $queueModel->addTask("@.RpcClient.Rpcclient",'Rpcclient','quiteHq',1,$param,0,$param['queue_unique_id']);
		if(empty($addTask)){
			return buildReturnData('', "添加任务失败".$queueModel->getLastSql(), 0);
		}
						
		return buildReturnData('', '处理成功', 1);						
	}
	
	/**
	 * 活期转零钱,活期记录相关处理流程,从指定的某条活期
	 * @param $uid 用户id
	 * @param $applyamount 转让金额
	 * @return  array
	 */
	public function doReceiveApplyForOne($uid,$applyamount,$hqid){
		
		$uaModel = new UsergatheringModel();		
		$receiveModel = new HqreceiveModel();
		
		//插入套现记录
		$receiveid = $receiveModel->addItem($uid, $applyamount);
		
		//获取用户的未锁定的活期记录 锁定的和结束的都忽略
		//$sql = "select * from hqrecords where applytime<=DATE_SUB(now(),INTERVAL 7 DAY) and uid = {$uid} and status = 0  and isdel = 0  order by yearrate asc,amount asc,created_at desc";
		//无锁定期了
		$sql = "select * from hqrecords where uid = {$uid} and status = 0  and isdel = 0 and id = {$hqid}";
		$ret = $this->query($sql);
		$ret = $ret[0];
		
		if(empty($ret)){
			return buildReturnData('', '不存在合规的活期记录', 0);
		}
		
		$canTakeAmount = $ret['amount'];
		
		if(floatcmp($applyamount, $canTakeAmount)>0){
			return buildReturnData('', '超过可退返的活期金额', 0);
		}
		
		
		//锁定记录
		$saveRet = $this->receiveLockRecord($hqid, $receiveid, $applyamount,true);
		if($saveRet['status'] != 1){
			return buildReturnData('', '变更活期记录失败'.$saveRet['info'], 0);
		}
		
		//写回款记录表
		$uaRet = $uaModel->createData($uid,1,$hqid,$applyamount,0);
		if($uaRet === false){
			return buildReturnData('',"本金回款记录添加失败".$uaModel->getLastSql(), 0);
		}

				
		//写profile
		$Profile = new ProfileModel();
		$Ret = $Profile->HqAmountIncome($uid, $applyamount);
		if($Ret === false){
			return buildReturnData('', '修改用户可用余额失败'.$Profile->getLastSql(), 0);
		}
		
		//写memberinfo
		$Mi = new MemberModel();
		$Ret = $Mi->HqAmountIncome($uid, $applyamount);
		if($Ret === false){
			return buildReturnData('', '修改用户投资资金字段失败'.$Mi->getLastSql(), 0);
		}
		
		//写资金交易记录
		$Fund = new FundhistoryModel();
		$Ret = $Fund->HqAmountIncome($uid, $applyamount);
		if(!$Ret){
			return buildReturnData('', '写资金交易记录失败'.$Fund->getLastSql(), 0);
		}
		
		
		//添加任务
		$queueModel = new QueueModel();
		$param = array();
		$param['uid'] = $uid;
		$param['amount'] = $applyamount;
		$param['transfer'] = 1;
		$param['queue_unique_id'] = $queueModel->createUniqueStr(serialize($param));
		$addTask = $queueModel->addTask("@.RpcClient.Rpcclient",'Rpcclient','quiteHq',1,$param,0,$param['queue_unique_id']);
		if(empty($addTask)){
			return buildReturnData('', "添加任务失败".$queueModel->getLastSql(), 0);
		}
						
		return buildReturnData('', '处理成功', 1);						
	}
	
	
	
	/**
	 * 活期套现锁定某活期记录
	 * @param $id 活期计划id
	 * @param $takeid int
	 * @param $takeamount decimal
	 * @param $needCreateNew boolean 是否需要生成新记录,提现申请时判断
	 * @return mix
	 */
	private function receiveLockRecord($id,$receiveid,$takeamount,$needCreateNew=false){
		$ret = $this->find($id);
		
		if($ret['status'] == 1){
			return buildReturnData('', '锁定活期记录失败,已经被锁定', 0);
		}
		
		$ret['receiveapplytime'] = array('exp','now()');
		$ret['receiveamount'] = $takeamount;
		$ret['receiveid'] = $receiveid;
		$ret['status'] = 1;//锁定
		//重新计算利息
		$NextRepayDay = getNextRepayDay();
		$ret['shouldinterestamount'] = $this->CalcInterest($ret['amount'], $ret['yearrate'], now(),$ret['starttime']);
		$saveRet = $this->where(array('id'=>$id))->save($ret);
		if(empty($saveRet)){
			return buildReturnData('', '锁定活期记录失败', 0);
		}
		//判断是否需要生成新记录
		if($needCreateNew == true){
			//生成新记录
			$newApplyAmount = $ret['amount']-$takeamount;
			if($newApplyAmount>0){
				$RecordId = $this->createData(8, $ret['uid'], $newApplyAmount, $ret['yearrate'], $ret['applytime']);
				if(empty($RecordId)){
					return buildReturnData('', '套现申请,添加活期记录失败'.$this->getLastSql(), 0);
				}
			}
		}
		return buildReturnData($RecordId, '锁定活期记录成功', 1);
	}
	
	/**
	 * 获取用户定投资产
	 */
	public function getTotalAmount($uid){
		$sql = "select sum(amount) as amount from hqrecords where uid = {$uid} and status = 0  and isdel = 0";
		$ret = $this->query($sql);
		return $ret['0']['amount']?$ret['0']['amount']:0;
	}
	
	
	/***********************动态key缓存***********************************/
	/**
	 * 某人当前参加的活期宝列表
	 */
	public function getHqlistKey($uid = null){
		$uid = $uid?$uid:session('Uid');
		return $this->getHqCacheNamespace()."uid-{$uid}-hqlist";
	}
	
	/**
	 * cache namespace 
	 */
	public function getHqCacheNamespace($uid = null){
		$uid = $uid?$uid:session('Uid');	
		//生成一个用来保存 namespace 的 key
		$ua_key = $this->cache->get($uid.'-'.self::GLO_HQ_CACHE_KEY);
		if($ua_key===false){
			//如果 key 不存在，则创建，默认使用当前的时间戳作为标识		
			$nowtime = time();
			$this->cache->set($uid.'-'.self::GLO_HQ_CACHE_KEY,$nowtime);
			$ua_key = $nowtime;
		}		
		return $ua_key;
	}
	
	public function clearHqCacheNamespace($uid = null){
		$uid = $uid?$uid:session('Uid');		
	 	return $this->cache->rm($uid.'-'.self::GLO_HQ_CACHE_KEY);
	}
	
}