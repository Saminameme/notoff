import React, { Component } from 'react';
import { observer , inject } from 'mobx-react';
import { Link } from "react-router-dom";
import { withRouter } from 'react-router-dom';
import { translate } from 'react-i18next';

import Column3Layout from '../component/Column3Layout';
import UserCard from '../component/UserCard';
import DocumentTitle from 'react-document-title';

@withRouter
@translate()
@inject("store")
@observer
export default class ClassNamePlaceHolder extends Component
{
    render()
    {
        const { t } = this.props;
        const main = <div>ClassNamePlaceHolder</div>;
        return <DocumentTitle title={''+'@'+t(this.props.store.appname)}><Column3Layout left={<UserCard/>} main={main} /></DocumentTitle>;
    }
}