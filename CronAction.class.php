<?php
/**
 *定时任务
 */
class CronAction extends CommonAction{
	
	/*每次扫描出的乐活计划数*/
	const CRON_LH_NUM = 50;
	
	/*每次扫描出的定投计划数*/
	const CRON_DT_NUM = 50;
	
	function _initialize(){
		
	}

	/**
     * 检测修改用户状态
     *
     */
    public function ChangeUserStatus() {
    	set_time_limit(0);
        $Member = M("member");
        $errorlog = M('errorlog');
        //获取所有Status为3的用户
        $UserInfo = $Member->where(array('status' => 3))->select();
        //循环检测时间是否达到了解锁时间
        $Now = time();
        foreach ($UserInfo as $v) {
        	$beginlocktime = $errorlog->where(array('phone' => $v['phone'], 'status' => 1))->order('created_at desc')->getField('beginlocktime');
            $beginlocktime = strtotime($beginlocktime);
            if ($Now - $beginlocktime > 3600) {
                $Member->where(array('phone' => $v['phone']))->save(array('status' => 1));
            }
        }
    }
	
    /**
     * 钱包用户月结单 微信推送消息
     * 每月最后一天下午三点执行一次
     */
    public function MonthBillPush(){
    	$member = M('member');
    	$fundhistory = M('fundhistory');
    	$profile = M('profile');
    	$Des = LOG_PATH . 'MonthBillPush_'.date('Ymd').'.log';//日志文件路径
		$template_id = '7RmlewARmYp-8AGPVKZsbdBNgzgQwJbPy6YxGe9n320';
    	$openidarr = $member->where("openid != ''")->field('openid,id')->select();
		import("@.Wechat.Weixin");
		$weixin = new Weixin();
		foreach ($openidarr as $key => $val) {
			//用于测试
			//if($val['id']<=100){
				$con = array();
				$con['typeid'] = array('in','100,102,205');
				$fromtime = date('Y-m-01 00:00:00',time());
				$totime = date('Y-m-d H:i:s',time());
				$con['created_at'] = array('between',array($fromtime,$totime));
				$con['uid'] = $val['id'];
				$income = $fundhistory->where($con)->sum('income');
				if(!$income){
					$income = 0;
				}
				$totalamount = $profile->where(array('uid'=>$val['id']))->getField('totalamount');
				$data = array(
					'first' => array('value'=>'尊敬的鲁班钱包用户,您的月度账单情况如下:', 'color'=>'#173177'),
					'keyword1' => array('value'=>date('Y-m-d'), 'color'=>'#173177'),
					'keyword2' => array('value'=>$totalamount.'元', 'color'=>'#173177'),
					'keyword3' => array('value'=>$income.'元', 'color'=>'#173177'),
					'remark' => array('value'=>'祝您财源滚滚！', 'color'=>'#173177'),			
				);
				$openid = $val['openid'];
				$url = 'http://qianbao.lubandai.cn/Member/Showfundhistory';  

				$arr = $weixin->PushTplMsg($openid, $template_id, $data, $url);
				Log::write('月结单推送消息!UID为'.$val['id'].' openid:'.$openid, Log::DEBUG,'',$Des);
				if(!$arr){
					Log::write('月结单推送消息失败!UID为'.$val['id'].' openid:'.$openid, Log::DEBUG,'',$Des);
				}
			//}
    	}
		   
    }

	
	/**
	 * 新版体验金退出
	 */
	public function TyamountQuitNew(){
		//获取未结束的体验金计划集合
		set_time_limit(0);
		//日志文件
		$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
		$tyModel = new TyamountdetailModel();
		$now = date('Y-m-d');
		try {
			$model = new Model();
			$model->startTrans();
			//收取利息
			$quitRet = $tyModel->TyamountQuitNew($now);
			if($quitRet['status'] != 1){
				$model->rollback();
				Log::write($quitRet['info']."sql:".$tyModel->getLastSql(), '','',$dst);
				exit();
			}			
			Log::write("{$now}批量执行体验金退出成功", '','',$dst);
			$model->commit();
		}catch (Exception $e) {
			$model->rollback();
			Log::write("发生异常".$e->getMessage().$e->getLine(), '','',$dst);
		}
	}
	
	
	/**
	 * 定投宝,收取利息.每日收息(2015-09-25变更,之前为到期一次性返利),执行时间比到期本金退出更早
	 * 每天扫描一次,不按照记录扫,按照人员扫
	 */
	public function CheckDtInterestIncome(){
		//获取未结束的活期宝计划集合
		set_time_limit(0);
		//日志文件
		$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
		
		####################2016-02-01###############################
		Log::write('定投每日派息功能暂时关闭,改为到期一次性派息','','',$dst);
		exit();
		###################################################
		
		$dtModel = new DtrecordsModel();
		$dtUserList = $dtModel->getDtElement();
		if(empty($dtUserList)){
			Log::write('今日无定投派息', '','',$dst);
			exit();
		}
		//总派利息,暂时这样,等定投派息重构再优化
		$totalinter = 0;
		foreach ($dtUserList as $k=>$v){
			$totalinter += $v['shouldinterestamount'];
		}
		
		//今日应派利息总和入监测表
		$dividendstatusModel = new DividendstatusModel();
		$addDividendRet = $dividendstatusModel->createData(2,$totalinter);
		if(empty($addDividendRet)){
			return buildReturnData('', '每日派息状态监测表入库失败'.$dividendstatusModel->getLastSql(), 0);
		}
		$realinter = 0;
		foreach ($dtUserList as $k=>$v){
			try {
				$model = new Model();
				$model->startTrans();	
				//收取利息
				$incomeRet = $dtModel->InterestIncome($v['uid'],$v['shouldinterestamount']);
				if($incomeRet['status'] != 1){
					$model->rollback();
					Log::write("用户{$v['uid']}收取定投利息失败:".$incomeRet['info'], '','',$dst);
					continue;
				}
				Log::write("用户{$v['uid']}收取定投利息成功:", '','',$dst);
				$realinter += $v['shouldinterestamount'];				
				$model->commit();
			}catch (Exception $e) {
				$model->rollback();
				Log::write("发生异常".$e->getMessage().$e->getLine(), '','',$dst);
			}
		}

		//变更派息监测表
		$item = $dividendstatusModel->changeStatus($addDividendRet,$realinter,1);
		if($item === false){
			return buildReturnData('', '变更派息监测表失败'.$dividendstatusModel->getLastSql(), 0);
		}
	}
	

	/**
	 * 扫描定投正常退出,执行时间在每日派息之后,2015-11-27 增加提前退出时间,对外不开放接口
	 * 每天扫描一次
	 */
	public function CheckDtQuit(){	
		set_time_limit(0);	
		$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
		$dtRecordsModel = new DtrecordsModel();
		$dtList = array();
		//取计划列表
		$dtListRet = $dtRecordsModel->getCurrentEndList();
		$dtList = $dtListRet['data'];
		if(empty($dtList)){
			Log::write('今日无需结束的定投计划','','',$dst);
			exit();
		}
		foreach ($dtList as $k=>$v){
			try {
				$model = new Model();
				$model->startTrans();
				
				$quitRet = $dtRecordsModel->quitDt($v['id']);
				if($quitRet['status'] != 1){
					$model->rollback();
					Log::write("定投计划退出处理失败,计划id{$v['id']},error:".$quitRet['info'], '','',$dst);
					continue;
				}
				Log::write("定投计划退出处理失败,计划id{$v['id']},退出成功", '','',$dst);
				$model->commit();
			} catch (Exception $e){
				$model->rollback();
				Log::write("发生异常".$e->getMessage(), '','',$dst);exit();
			}
		}
	}
	
	/**
	 * 乐活每周派息,只有周周彩每周派息年息0.35,执行时间比到期本金退出更早
	 * 每天扫描一次,不按照记录扫,按照人员扫
	 */
	public function CheckLhInterestIncome(){
		set_time_limit(0);
		//日志文件
		$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
		$lhRecordsModel = new LhrecordsModel();
		$lhList = array();
		//取计划列表
		$lhList = $lhRecordsModel->getZZcElement();
		if(empty($lhList)){
			Log::write('今日暂无需要派息的乐活','','',$dst);
			exit();
		}
		foreach ($lhList as $k=>$v){
			try {
				$model = new Model();
				$model->startTrans();
				$quitRet = $lhRecordsModel->zzcInterestIncome($v['uid'],$v['interest']);
				if($quitRet['status'] != 1){
					$model->rollback();
					Log::write("乐活派息失败,用户{$v['uid']}收取乐活利息失败:".$quitRet['info'], '','',$dst);
					continue;
				}
				Log::write("乐活派息成功,计划id{$v['id']}", '','',$dst);
				$model->commit();
			} catch (Exception $e) {
				$model->rollback();
				Log::write("发生异常".$e->getMessage().$e->getLine(), '','',$dst);
			}
		}
	}
	
	/**
	 * 扫描乐活正常退出
	 * 每天扫描一次
	 */
	public function CheckLhQuit(){
		set_time_limit(0);
		//日志文件
		$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
		$lhRecordsModel = new LhrecordsModel();
		$lhList = array();
		//取计划列表
		$lhListRet = $lhRecordsModel->getCurrentEndList();
		$lhList = $lhListRet['data'];
		if(empty($lhList)){
			Log::write('今日无需结束的乐活计划','','',$dst);
			exit();
		}
		foreach ($lhList as $k=>$v){
			try {
				$model = new Model();
				$model->startTrans();
		
				$quitRet = $lhRecordsModel->quitLh($v['id']);
				if($quitRet['status'] != 1){
					$model->rollback();
					Log::write("乐活计划退出处理失败,计划id{$v['id']},error:".$quitRet['info'], '','',$dst);	
					continue;
				}				
				$model->commit();
			} catch (Exception $e) {
				$model->rollback();
				Log::write("发生异常".$e->getMessage().$e->getLine(), '','',$dst);
			}				
		}
		Log::write('乐活批量退出成功','','',$dst);
	}

	/**
	 * 活期宝,收取利息.一月2次，每月5号调息
	 * 每天扫描一次,不按照记录扫,按照人员扫
	 */
	public function CheckHqInterestIncome(){
		//获取未结束的活期宝计划集合
		set_time_limit(0);
		
		//日志文件
		$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
		$hqModel = new HqrecordsModel();
		$hqUserList = $hqModel->getHqElement();
		foreach ($hqUserList as $k=>$v){
			try {
				$model = new Model();
				$model->startTrans();	
				//收取利息
				$incomeRet = $hqModel->InterestIncome($v['uid'],$v['shouldinterestamount']);
				if($incomeRet['status'] != 1){
					$model->rollback();
					Log::write($incomeRet['info'], '','',$dst);
					continue;
				}				
				$model->commit();
			}catch (Exception $e) {
				$model->rollback();
				Log::write("发生异常".$e->getMessage().$e->getLine(), '','',$dst);
			}
		}	
	}
	
	
	/**
	 * 活期宝,新版活期派息
	 * 每天扫描一次
	 */
	public function CheckHqInterestIncomeNew(){
		set_time_limit(0);
		//日志文件
		$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
		$hqModel = new HqrecordsModel();
		try {
			$model = new Model();
			$model->startTrans();
			//收取利息
			$incomeRet = $hqModel->InterestIncomeNew();
			if($incomeRet['status'] != 1){
				$model->rollback();
				Log::write($incomeRet['info'], '','',$dst);
				exit();
			}
			Log::write($incomeRet['info'], '','',$dst);
			$model->commit();
		}catch (Exception $e) {
			$model->rollback();
			Log::write("发生异常".$e->getMessage().$e->getLine(), '','',$dst);
		}
	}
	
	/**
	 * 体验金,收取利息.一月2次，每月5号调息
	 * 每天扫描一次,不按照记录扫,按照人员扫
	 */
	public function CheckTyInterestIncome(){
		//获取未结束的体验金计划集合
		set_time_limit(0);
		//日志文件
		$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
		$tyModel = new TyamountdetailModel();
		$TyUserList = $tyModel->getTyElement();
		foreach ($TyUserList as $k=>$v){
			try {
				$model = new Model();
				$model->startTrans();
				//收取利息
				$incomeRet = $tyModel->InterestIncome($v['uid'],$v['shouldinterestamount']);
				if($incomeRet['status'] != 1){
					$model->rollback();
					Log::write($incomeRet['info'], '','',$dst);
					continue;
				}
				$model->commit();
			}catch (Exception $e) {
				$model->rollback();
				Log::write("发生异常".$e->getMessage().$e->getLine(), '','',$dst);
			}
		}
	}
	
	/**
	 * 新版 体验金,收取利息.一月2次，每月5号调息
	 * 每天扫描一次,不按照记录扫,按照人员扫
	 */
	public function CheckTyInterestIncomeNew(){
		//获取未结束的体验金计划集合
		set_time_limit(0);
		//日志文件
		$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
		$tyModel = new TyamountdetailModel();
		try {
			$model = new Model();
			$model->startTrans();
			//收取利息
			$incomeRet = $tyModel->InterestIncomeNew();
			if($incomeRet['status'] != 1){
				$model->rollback();
				Log::write($incomeRet['info'], '','',$dst);
				exit();
			}
			Log::write($incomeRet['info'], '','',$dst);
			$model->commit();
		}catch (Exception $e) {
			$model->rollback();
			Log::write("发生异常".$e->getMessage().$e->getLine(), '','',$dst);
		}
	}
	
	
	/**
	 * 更新用户手机号归属地
	 * SELECT * FROM `member` where PhoneAreaCity='' or PhoneAreaProvince='' or PhoneAreaSP='';
	 */
	public function UpdateMemberPhoneArea() {
		set_time_limit ( 0 );
		$StartID = I ( 'StartID' );
		$EndID = I ( 'EndID' );
		$Empty = I ( 'Empty' );
		$Str = "phoneAreaProvince=''";// or phoneAreaProvince='' or phoneAreaSP='' or phoneAreaCity='未知' or phoneAreaProvince='未知' or phoneAreaSP='未知'
		if ($StartID) {			
			if($EndID){
				$Cond = "id>={$StartID} and id<={$EndID} and {$Str}";
			}else{
				$Cond = "id>={$StartID} and {$Str}";
			}
		} elseif ($Empty) {
			$Cond =  $Str;
		} else {
			die ( 'StartID && Empty can not both empty!' );
		}
		
		import('@.PhoneArea.PhoneArea');
		$PhoneArea = new PhoneArea();
		$memberModel = M ( 'Member' );

		$BeginId = 0;
		$EndId = 0;
		$pos = 0;
		$perpage = 100;
		for($page=1;;){
			$pos = ($page-1)*$perpage;

			$memberList = $memberModel->where ( $Cond )->limit($pos, $perpage)->select ();
			if (empty ( $memberList )) {
				break;
				//die ( 'memberList is empty!' );
			}
			
			foreach ( $memberList as $key => $value ) {

				if(0 == $BeginId){
					$BeginId = $value['id'];
				}
				$EndId = $value['id'];
			
			//if($key>=2){ echo "Key:{$key},break<br>"; break; }
			
			$Phone = $value ['phone'];
			
			//echo $Phone;
			
			if (empty ( $Phone )) {
				echo "Phone(Key:{$key}) Empty,Continue.";
				continue;
			}
			$phoneAddress = $PhoneArea->phoneAddress ( $Phone, 3 );
			
			//var_dump($phoneAddress);
			
			if (false === $phoneAddress) {
				echo "phoneAddress(Phone:{$Phone}) Empty,Continue.";
				continue;
			}
			
			//echo $phoneAddress."<br><br>"; continue;
			
			$phoneAddressArr = explode ( ' ', $phoneAddress );
			$memberModel->id = $value ['id'];
			$memberModel->phoneAreaProvince = $phoneAddressArr [0];
			$memberModel->phoneAreaCity = $phoneAddressArr [1];
			$memberModel->phoneAreaSP = $phoneAddressArr [2];
				if (false === $memberModel->save ()) {
					echo "Save(Phone:{$Phone}) Fail,Sql:{$memberModel->getLastSql()},Continue.<br>";
				}
			}//end foreach
			$page++;
		}//end for
		$this->ajaxReturn(array('BeginId'=>$BeginId, 'EndId'=>$EndId), 'Update Finished.', 1);
	}//end method
	
	/**
	 * 更新用户登录IP归属地
	 */
	public function UpdateMemberLoginLocation() {
		set_time_limit ( 0 );
		$StartID = I ( 'StartID' );
		$Empty = I ( 'Empty' );
		if ($StartID) {
			$Cond = "id>={$StartID}";
		} elseif ($Empty) {
			$Cond = "ipLoc='' or ipLoc='未知'";
		} else {
			die ( 'StartID && Empty can not both empty!' );
		}
				
		$Model = M ( 'logindetail' );
		$List = $Model->where ( $Cond )->limit ( 60000 )->select ();
		if (empty ( $List )) {
			die ( 'List is empty!' . $Model->getLastSql () );
		}
		
		import ( 'ORG.Net.IpLocation' ); // 导入IpLocation类
		$Ip = new IpLocation ( 'UTFWry_20140615.dat' ); // 实例化类 参数表示IP地址库文件
// 		$area = $Ip->getlocation('203.34.5.66'); // 获取某个IP地址所在的位置
// 		var_dump($area);die;
		
		foreach ( $List as $key => $value ) {
			
// 			if($key>=2){ echo "Key:{$key},break<br>"; break; }
			
			$Data = $value ['ip'];
			if (empty ( $Data )) {
				echo "Data(Key:{$key}) Empty,Continue.<br>";
				continue;
			}
			$IpInfoArr = $Ip->getlocation ( $Data ); // 获取某个IP地址所在的位置
			$NewData = $IpInfoArr ['country'] . ',' . $IpInfoArr ['area'];
			
// 			var_dump($NewData);
			
			if ('' == $NewData) {
				$NewData = '未知';
// 				echo "NewData(Data:{$Data}) Empty,Continue.<br>";
// 				continue;
			}
			
// 			echo $NewData; continue;
			
// 			echo "Data:{$Data},NewData:{$NewData}<br>";
			
			$Model->id = $value ['id'];
			$Model->ipLoc = $NewData;
			if (false === $Model->save ()) {
				echo $Model->getLastSql () . ",Save(Data:{$Data}) Fail,Continue.<br>";
			}
		}
		echo 'Update Finished.';
	}
	
	/**
	 * 批量更新所有微信用户信息
	 * 所有记录都刷新，因为头像可能被更新过
	 */
	public function UpdateAllWxUserInfo(){
		$Model = D('Member');
		$ret = $Model->UpdateAllWxUserInfo();
		var_dump($ret);
	}
	
	/**
	 * 更新uavisiturl表访问地址
	 * 每天一次
	 */
	public function UpdateUavisiturlLocation(){
		$Model= M('uavisiturl');
		$result=$Model->field('id,ip')->where("location=''")->select();
		import ( 'ORG.Net.IpLocation' ); // 导入IpLocation类
		$Ip = new IpLocation ( 'UTFWry_20140615.dat' ); // 实例化类 参数表示IP地址库文件
		if(!empty($result)){
			foreach($result as $k=>$v){
				$ip=$v['ip'];
				if(empty($ip)){
					echo "Data(id:{$v['id']})ip is Empty,Continue.<br>";
					continue;
				}
				$IpInfoArr = $Ip->getlocation ( $ip ); // 获取某个IP地址所在的位置
				$NewData = $IpInfoArr ['country'] . ',' . $IpInfoArr ['area'];
				if(''==$NewData){
					$NewData='未知地址';
				}
				$Model->id = $v ['id'];
				$Model->location = $NewData;
				if (false === $Model->save ()) {
					echo $Model->getLastSql () . ",Save(Data:{$v['id']}) Fail,Continue.<br>";
				}
			}
			echo 'Update Finished.';
		}else{
			echo 'There is no need to update the data';
		}
	}
	
	/**
	 * 更新用户默认值设置
	 */
	public function UpdateUaDefaultSet(){
		//--------- 连连支行代码 begin ---------
		$model = new Model();
		//插入新member记录
		$sql = "insert into uadefaultset(uid,phone,PhoneAreaProvince,PhoneAreaCity)
SELECT id,phone,PhoneAreaProvince,PhoneAreaCity from member where PhoneStatus=1 and id not in(
select uid from uadefaultset)";
		$model->query($sql);
		//刷新没有guess的记录
		$sql = "update uadefaultset set guess_province_code=(select `code` from citycode where pid=0 and `name` like CONCAT('%',PhoneAreaProvince,'%') limit 1) where guess_province_code is null and PhoneAreaProvince is not null and PhoneAreaProvince!=''";
		$model->query($sql);
		$sql = "update uadefaultset set guess_city_code=(select `code` from citycode where pid!=0 and `name` like CONCAT('%',PhoneAreaCity,'%') limit 1) where guess_city_code is null and PhoneAreaCity is not null and PhoneAreaCity!=''";
		$model->query($sql);
		//刷新real记录
		$sql = "update uadefaultset as u set real_province_code=(select province_code from bankcards as b where `status`=1 and b.uid=u.uid)";
		$model->query($sql);
		$sql = "update uadefaultset as u set real_city_code=(select city_code from bankcards as b where `status`=1 and b.Uid=u.uid)";
		$model->query($sql);
		//--------- 连连支行代码 end ---------
	}
	
	/**
	 * 优惠券自动申请兑现,暂定每天早上7点
	 */
	public function cash(){		
		try {
			set_time_limit(0);
			$model = new Model();
			$model->startTrans();
			//日志文件
			$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
			$test = I('test');
			if(!empty($test)){
				$cashday = $test;
			}else{
				$cashday = date('Y-m-d',strtotime('-1 day'));
			}
			
			$coupon=new coupon();
			$cashRet = $coupon->cashApply($cashday);
			if($cashRet['status'] != 1){
				$model->rollback();
				Log::write("兑换申请失败".$cashRet['info'], '','',$dst);
				exit();
			}
			$model->commit();			
		} catch (Exception $e) {
			$model->rollback();
			Log::write("发生异常".$e->getMessage(), '','',$dst);
		}
	}
	
	/**
	 * 扫描过期优惠券,暂定每天凌晨12点
	 */
	public function scanCoupon(){
		try {
			set_time_limit(0);
			$model = new Model();
			$model->startTrans();
			//日志文件
			$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
				
			//获取所有未结束的优惠券,扫描是否时间截止
			$couponModel = M('coupon');
			$now = date('Y-m-d');
			$sql = "insert into coupon select * from coupon where status = 1 and Substring(endtime, 1, 10) >= '{$now}' on DUPLICATE key UPDATE status = 3";
			$ret = $couponModel->execute($sql);
			if(empty($ret)){
				$model->rollback();
				Log::write("执行过程失败".$couponModel->getLastSql(),'','',$dst);
				exit();
			}
				
			$model->commit();
		} catch (Exception $e) {
			$model->rollback();
			Log::write("发生异常".$e->getMessage(), '','',$dst);
		}
	}
	
	/**
	 * 满100自动购买债权
	 */
	public function AutoBuyHq(){
		set_time_limit(0);
		$queueModel = new QueueModel();
		$profileModel = new ProfileModel();
		$platsaleModel = new PlatsaleplanModel();
		$memberModel = new MemberModel();
		$hqModel =  new HqrecordsModel();
		$sql = "select * from profile where usableamount>=100";
		$proList = $profileModel->query($sql);
		foreach ($proList as $k=>$v){
			$buyamount = 0;//购买金额
			$platinfo = $platsaleModel->getAmountAndNextStage();//当前销售计划
			$canBuyAmount = $platinfo['balance'];//可购买金额		
			$userBalance = $v['usableamount'];//余额
			if(floatcmp($userBalance,$canBuyAmount)>=0){
				$buyamount = $canBuyAmount;
			}else{
				$buyamount = floor($userBalance/100)*100;
			}
			//检查当前已经购买的活期总额
			$investInfo = $memberModel->GetMemberColAmountInfo($v['uid']);
			$totalHq = $investInfo['hqtzamount'];
			//活期限额
			$maxAmountPerUser = $hqModel->getMaxAmountPerUser();
			$totalHq = $totalHq+$buyamount;
			if(floatcmp($totalHq, $maxAmountPerUser)>0){
				$moreAmount = $totalHq - $maxAmountPerUser;//超过的金额
				$buyamount = $buyamount-$moreAmount;
				if($buyamount<=0 || $buyamount%100 != 0){
					continue;
				}
			}
			
			$addTask = $queueModel->addTask("",'Hqrecords','Buy',0,array($v['uid'],$buyamount,1));
			if(empty($addTask)){
				return buildReturnData('', "添加任务失败".$queueModel->getLastSql(), 0);
			}
		}
	}

	/**
	 * 计划任务下线5% 临时表
	 */
	public function DownLevelIncome()
	{
		set_time_limit(0);
		$RecommendModel = new RecommendModel();
		$RecommendModel->startTrans();
		$percent = 0.05;
		$today = I('today', date('Y-m-d'));
		$RecommendModel->chargeUplevel5percent($percent, $today);
		$RecommendModel->commit();
		$this->ajaxReturn('', '执行成功！', 1);
	}

	/**
	 * 计划任务下线5% 正式大于0.01元的数据库入库
	 */
	public function DownLevelIncomeReal()
	{
		set_time_limit(0);
		$RecommendModel = new RecommendModel();
		$today = I('today', date('Y-m-d'));
		$RecommendModel->chargeUplevel5percentfinal($today);
		$this->ajaxReturn('', '执行成功！', 1);
	}

	/**
	检查微信的AccessToken是否有效，若失效则刷新
	**/
	public function CheckAccessToken(){
		import("@.Wechat.Weixin");
		$weixin = new Weixin();
		$weixin->GetAccessTokenCron();
	}

	
	/**
	 * 每天上午十点周周彩自动领取
	 */
	public function AutoGetZzcLottery(){
		//获取未结束的周周彩集合
		set_time_limit(0);
		//日志文件
		$dst = LOG_PATH.$this->getActionName().'_'.__FUNCTION__.'.log';
		
		import('@.Capacity.Capacity2');
		$capacity = new Capacity2('qb_lottery_getLotteryForZZC_lock', 1);
		$access = $capacity->access();
		if($access === false){
			Log::write('当前有队列正在执行,请等待...', '','',$dst);
			exit();
		}
		
		$lhModel = new LhrecordsModel();
		$LotteryModel  = new LotteryModel();
		$lhlist = $lhModel->getSysZzcList();
		
		if(empty($lhlist)){
			$capacity->dec();
			Log::write('没有周周彩', '','',$dst);
			exit();
		}
		
		foreach ($lhlist as $k=>$v){
			try {
				$model = new Model();
				$model->startTrans();
				
				$ret = $LotteryModel->getLotteryZzc($v['uid'], $v['id']);
				if ($ret['status'] == 0) {
					$model->rollback();
					Log::write($ret['info'], '','',$dst);
					continue;
				}
				Log::write("用户{$v['uid']}彩票自动领取成功", '','',$dst);
				$this->pushCronZZC($v['uid']);
				$model->commit();
			}catch (Exception $e) {
				$capacity->dec();
				$model->rollback();
				Log::write("发生异常".$e->getMessage().$e->getLine(), '','',$dst);
			}
		}
		
		$capacity->dec();
	}
	
	private function pushCronZZC($uid){
		$text = "彩票新鲜出炉，您的钱包里又种下了500万的希望。快去【我的钱包】里看看吧。";
		$openid = getOpenid($uid);
		import("@.Wechat.Weixinpush");
		$Weixinpush = new Weixinpush($text);
		$arr = $Weixinpush->WeixinPushCsMsgText($openid);
		Log::write('周周彩领取推送消息!Uid:'.$uid.' openid:'.$openid, Log::DEBUG,'',$Des);
		if(!$arr){
			Log::write('周周彩领取推送消息失败!Uid:'.$uid, Log::DEBUG,'',$Des);
		}
	}


    // memberinfo 极速数据噢耶版
    public function memberinfoflashinfo()
    {
    	$Model = new Model();
        // 更新推荐表的实名信息
        $updateRecommendSql = <<<EOT
update recommend set tname=(select realname from memberinfo where uid=tuid and realnamestatus=1) where tname is null;
update recommend set btname=(select realname from memberinfo where uid=btuid and realnamestatus=1) where btname is null;
EOT;
        $Model->execute($updateRecommendSql);

		// 更新用户的推荐人信息
        $updateRecommendSql = <<<EOT
update memberinfo as m set rtid=(select tuid from recommend where btuid=m.uid) where rtid is null;
update memberinfo as m set rtname=(select tname from recommend where btuid=m.uid) where rtname is null;
EOT;
        $Model->execute($updateRecommendSql);

        /*// 更新用户的推荐人信息
        $updateRecommendSql = <<<EOT
update memberinfo m
inner join (select
recommend.tuid,
memberinfo.realname,
recommend.btuid
from recommend
inner join memberinfo on memberinfo.uid = recommend.tuid) as c on m.uid = c.btuid
set m.rtid = c.tuid, m.rtname = c.realname
EOT;
        $Model->execute($updateRecommendSql);*/

        // 更新用户推荐名下推荐人数
        $updateRecommendPeopleSal = <<<EOT
update memberinfo m
inner join (select
tuid, count(*) as tpoeple
from recommend group by tuid) r on m.uid = r.tuid
set m.rcpeople = r.tpoeple
EOT;
        // $updateRecommendSql = "update memberinfo m set rcpeople = (select count(id) from recommend where tuid=m.uid)";
        $Model->execute($updateRecommendSql);//lsj:update memberinfo m set rcpeople = (select count(id) from recommend where tuid=m.uid);

        // 更新用户对应用户的推荐奖励
        $updateTJSql = <<<EOT
update memberinfo m
inner join (select user_id, sum(amount) as rcamount from syscharge where (amount = 4 or amount = 2) group by user_id) s on m.uid = s.user_id
set m.`rcamount` = s.rcamount
EOT;

        $Model->execute($updateTJSql);

        //2016.1.18 lsj：下线投资收益在购买的地方做统计
        /* //更新用户的下线投资额
        $updateXXSql = <<<EOT
update memberinfo mi
inner join (select
rtid,
sum(hqtzamount) as xxhqtzamount,
sum(dttzamount) as xxdttzamount,
sum(lhtzamount) as xxlhtzamount
from memberinfo where hqtzamount >= 0 and dttzamount >= 0 and lhtzamount >= 0 and rtid <> 0 group by rtid) t on mi.uid = t.rtid
set mi.xxhqtzamount = t.xxhqtzamount, mi.xxdttzamount = t.xxdttzamount, mi.xxlhtzamount = t.xxlhtzamount
EOT;

        $Model->execute($updateXXSql); */

        $this->ajaxReturn('', '刷新完成', 1);
    }
    /**
     * 每过30分钟将smssendlog表数据挪到smssendlog_his表
     * @return [type] [description]
     */
    public function smssendlogMove(){
    	$model=new Model();
    	$Des = LOG_PATH . 'smssendlogMove_'.date('Ymd').'.log';//日志文件路径
    	$sql1='select max(id) as mid from smssendlog where created_at<date_sub(now(),interval 30 minute);';
    	$result=$model->query($sql1);
    	$maxid=$result[0]['mid'];
    	if(empty($maxid)){

			Log::write('sql1语句执行失败或没有数据'.$model->getLastSql(), Log::DEBUG,'',$Des);
			return false;
    	}
    	
    	$sql2='insert into smssendlog_his SELECT * from smssendlog where id<='.$maxid;
    	$result2=$model->execute($sql2);
    	if($result2===false){
    		Log::write('sql2语句执行失败'.$model->getLastSql(), Log::DEBUG,'',$Des);
			return false;
    	}

    	$sql3='DELETE from smssendlog where id<='.$maxid;
    	$result3=$model->execute($sql3);
    	if($result2===false){
    		Log::write('sql3语句执行失败'.$model->getLastSql(), Log::DEBUG,'',$Des);
			return false;
    	}

    	$sql4='DELETE from actioncodelog where created_at<date_sub(now(),interval 40 minute);';
    	$result4=$model->execute($sql4);
    	if($result4===false){
    		Log::write('sql4语句执行失败'.$model->getLastSql(), Log::DEBUG,'',$Des);
			return false;
    	}
    }
    
    /**
     * 定期定量执行彩票导入计划   彩票将从临时表被导入到正式表
     */
    public function temptolottery()
    {
        //到期的记录
        $list = M("temptolottery")->where("e_time <= '".date("Y-m-d H:i:s",time())."'")->select();
        if(!empty($list)){
            foreach($list as $v){
                $info = D("Templottery")->importLotteryqh($v['qh'],$v['importNum']);
                if($info['status']){
                    M("temptolottery")->where("id = ".$v['id'])->delete();
                }
                echo $info['msg'];
            }
        }else{
            echo "没有到期的导入彩票计划";
        }
        
    }
    
    /**
     * 定时扫描dividendstatus表进行当天角色收益奖励发放
     */
    public function cronIncomeReward(){
		set_time_limit(0);
		//日志文件
		$dst = LOG_PATH . 'cronIncomeReward_'.date('Ymd').'.log';//日志文件路径
		
		//判断开关 yl
		if(C('OEPN_CRON_XIAXIAN_REWARD') != 1){
			Log::write("开关 未打开,不进行下线收益", '','',$dst);
			exit();			
		}
		
		//判断状态
		$divModel = new DividendstatusModel();
		$rolereward=new RoleReward();
		$repayDay=now();
		$result=$divModel->checkCanReward($repayDay);
		if($result['status']!=1){
			
			Log::write("检查返回结果为：".$result['info'], '','',$dst);
			return false;
		}
		try {
			Log::write(date('Y-m-d').'开始发放角色奖励', '','',$dst);
			$model = new Model();
			$model->startTrans();
			//收取利息
			$incomeRet = $rolereward->IncomeRewardNew();
			if($incomeRet['status'] != 1){
				$model->rollback();
				Log::write($incomeRet['info'], '','',$dst);
				exit();
			}
			Log::write($incomeRet['info'], '','',$dst);
			$model->commit();
		}catch (Exception $e) {
			$model->rollback();
			Log::write("发生异常".$e->getMessage().$e->getLine(), '','',$dst);
		}
    }
    

    
    /**
     * 记录每天商户旗帜颜色的数量
     */
    public function flagColorNum(){
        //判断今天是否已经记录了。如果记录了 就不在记录了
        $rs = M('Flagcolornum')->where('create_at > CURDATE() AND create_at < date_add(curdate(), interval 1 day)')->select();
        if(!$rs){
            $sql = "INSERT INTO flagcolornum (red,grey,green,create_at) VALUES((SELECT count(*) from merchant WHERE `status` = 1),(SELECT count(*) from merchant WHERE `status` = 2 AND (lastVisitSid = '' or lastVisitSid is null)),(SELECT count(*) from merchant WHERE `status` = 2 AND lastVisitSid > 0 ),NOW());";
            $Model = new Model();
            $Model->execute($sql);
        }
        
    }

	/**
	 * 刷商户推荐数量
	 * xwb 2015-12-15
	 */
	public function refreshmerchatrecommend()
	{
		$sql = <<<EOT
UPDATE merchant AS m
SET tnums = (
	SELECT
		tnums
	FROM
		(
			SELECT
				wechatuserinfo.fromSceneId,
				count(wechatuserinfo.fromSceneId) AS tnums
			FROM
				merchant
			INNER JOIN scene ON scene.id = merchant.qrcodeid
			INNER JOIN wechatuserinfo ON wechatuserinfo.fromSceneId = merchant.qrcodeid
			WHERE
				merchant. STATUS = 1
			AND merchant.verifyStatus = 1
			AND merchant.uid <> 0
			AND merchant.qrcodeid <> 0
			AND merchant.qrcode_url IS NOT NULL
			AND wechatuserinfo.uid <> 0
			GROUP BY
				wechatuserinfo.fromSceneId
		) AS mi
	WHERE
		mi.fromSceneId = m.qrcodeid
);
EOT;
		$Model = new Model();
		$Model->execute($sql);
	}
}