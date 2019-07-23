<?php

namespace XCLinkTrack;

use App\Jobs\Job;
use Illuminate\Support\Facades\DB;
use App\Models\CustomerSource;

class LinkTrackJob extends Job
{
    protected $source_params;

    public function __construct($source_params)
    {
        $this->source_params = $source_params;
    }
    
    public function handle()
    {
        //查询该客户是否已有记录
        if (CustomerSource::where('customer_id', $source_params['customer_id'])->first()) {
            return $this->failed('已记录');
        }
        
        //客户为第一层级
        if ($this->source_params['parent_id'] == 0) {
            //查询最大右值
            $max_rft = CustomerSource::max('rft');
            if ($max_rft) {
                $this->source_params['lft'] = $max_rft + 1;  //当前节点左值
                $this->source_params['rft'] = $max_rft + 2;  //当前节点右值
            } else {
                $this->source_params['lft'] = 1;  //当前节点左值
                $this->source_params['rft'] = 2;  //当前节点右值
            }
            $this->source_params['level'] = 1;  //当前节点层级
            if (!CustomerSource::create($this->source_params)) {
                return $this->failed('记录失败');
            }
        } else { //来自其他客户分享
            //查询父级信息
            $parant_info = CustomerSource::where('customer_id', $this->source_params['parent_id'])->first(['rft', 'level']);
            if ($parant_info) {
                DB::beginTransaction();
                //更新大于等于该节点右值的数据
                CustomerSource::where('lft', '>', $parant_info->rft)
                    ->increment('lft', 2);
                CustomerSource::where('rft', '>=', $parant_info->rft)
                    ->increment('rft', 2);
                
                //插入当前节点数据
                $this->source_params['lft'] = $parant_info->rft;
                $this->source_params['rft'] = $parant_info->rft + 1;
                $this->source_params['level'] = $parant_info->level + 1;
                if (!CustomerSource::create($this->source_params)) {
                    DB::rollback();
                    return $this->failed('记录失败');
                }
                DB::commit();
            } else {    //理论下不存在该情况，为增强健壮性，如果走该逻辑，先给定特殊值，后期手动处理
                $this->source_params['lft'] = 99999999;
                $this->source_params['rft'] = 99999999;
                $this->source_params['level'] = 99999999;
                if (!CustomerSource::create($this->source_params)) {
                    return $this->failed('记录失败!');
                }
            }
        }

        return true;
    }
}
